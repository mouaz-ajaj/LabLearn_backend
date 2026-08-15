<?php

namespace App\Services\Quiz\GeneralQuestions;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;
use App\Services\Quiz\GeneralQuestions\Kbs\LiverRuleTriggerCatalog;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\AbbreviationFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\AliasRecognitionFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\CategoryComparisonFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\CrossPanelRelationshipFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\DerivedValueInputsFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\GeneralQuestionTemplateFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\MissingSupportingInformationFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\OptionalSupportingInputsFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\PanelMembershipFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\PatternConditionRecognitionFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\RequiredInputsFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\RuleConclusionMatchingFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\RuleInputRecognitionFamily;
use App\Services\Quiz\GeneralQuestions\TemplateFamilies\StatusClassificationFamily;

/**
 * Orchestrates KBS parsing -> template families -> per-question validation ->
 * near-duplicate rejection -> a final, bank-wide-validated collection of General
 * questions, grouped by category. Deterministic: given the same KBS files and the
 * same generator_version, calling generate() twice produces the identical set of
 * stable_source_keys (see tests/Feature/GeneralQuestionBank/DeterminismTest.php).
 *
 * This class only ever produces PHP value objects — it never touches the database.
 * See RefreshGeneralQuestionBank for the command that validates the whole bank and
 * persists it inside a transaction.
 */
final class GeneralQuestionGenerator
{
    /** @return list<GeneralQuestionTemplateFamily> */
    public static function defaultFamilies(): array
    {
        return [
            new PanelMembershipFamily,
            new AbbreviationFamily,
            new AliasRecognitionFamily,
            new RequiredInputsFamily,
            new OptionalSupportingInputsFamily,
            new RuleInputRecognitionFamily,
            new PatternConditionRecognitionFamily,
            new StatusClassificationFamily,
            new DerivedValueInputsFamily,
            new CrossPanelRelationshipFamily,
            new RuleConclusionMatchingFamily,
            new MissingSupportingInformationFamily,
            new CategoryComparisonFamily,
        ];
    }

    /** @param list<GeneralQuestionTemplateFamily>|null $families */
    public function __construct(
        private readonly string $knowledgeBasePath,
        private readonly string $generatorVersion,
        private readonly GeneralQuestionValidator $validator,
        private ?array $families = null,
    ) {
        $this->families ??= self::defaultFamilies();
    }

    /**
     * @return array{
     *     questionsByCategory: array<string, list<GeneratedGeneralQuestion>>,
     *     droppedInvalidCount: int,
     *     droppedDuplicateCount: int,
     *     skippedLiverRuleCodes: list<string>,
     * }
     */
    public function generate(): array
    {
        $kb = KbsKnowledgeBase::load($this->knowledgeBasePath, new LiverRuleTriggerCatalog);

        /** @var array<string, list<GeneratedGeneralQuestion>> $byCategory */
        $byCategory = [];
        foreach (ReportTestCategory::cases() as $category) {
            $byCategory[$category->value] = [];
        }

        $seenKeys = [];
        $seenTextKeys = [];
        $droppedInvalid = 0;
        $droppedDuplicate = 0;

        foreach ($this->families as $family) {
            foreach ($family->generate($kb, $this->generatorVersion) as $question) {
                $errors = $this->validator->validateQuestion($question);
                if ($errors !== []) {
                    $droppedInvalid++;

                    continue;
                }
                if (isset($seenKeys[$question->stableSourceKey]) || isset($seenTextKeys[$question->normalizedTextKey()])) {
                    $droppedDuplicate++;

                    continue;
                }
                $seenKeys[$question->stableSourceKey] = true;
                $seenTextKeys[$question->normalizedTextKey()] = true;
                $byCategory[$question->category->value][] = $question;
            }
        }

        // Stable final ordering (by stable_source_key) so repeated runs produce byte-
        // identical sequences, independent of family/iteration order.
        foreach ($byCategory as $category => $questions) {
            usort($questions, static fn (GeneratedGeneralQuestion $a, GeneratedGeneralQuestion $b): int => $a->stableSourceKey <=> $b->stableSourceKey);
            $byCategory[$category] = $questions;
        }

        return [
            'questionsByCategory' => $byCategory,
            'droppedInvalidCount' => $droppedInvalid,
            'droppedDuplicateCount' => $droppedDuplicate,
            'skippedLiverRuleCodes' => $kb->skippedLiverRuleCodes(),
        ];
    }
}
