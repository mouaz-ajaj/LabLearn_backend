<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

interface GeneralQuestionTemplateFamily
{
    /** Stable identifier stored as questions.template_family. */
    public function code(): string;

    /** @return iterable<GeneratedGeneralQuestion> */
    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable;
}
