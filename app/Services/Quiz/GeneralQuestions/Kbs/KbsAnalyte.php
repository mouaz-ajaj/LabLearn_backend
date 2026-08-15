<?php

namespace App\Services\Quiz\GeneralQuestions\Kbs;

use App\Enums\ReportTestCategory;

/**
 * One analyte as understood by the KBS knowledge base (tests.json / liver_tests.json),
 * flattened into exactly the fields the General Question templates need. Never
 * constructed with invented data — every field is copied or filtered from real KBS
 * JSON (see KbsKnowledgeBase::load()).
 */
final class KbsAnalyte
{
    /**
     * @param  list<string>  $safeAliases  Aliases suitable for the Alias Recognition
     *                                     template: excludes the short name, the full
     *                                     name, and any alias flagged ambiguous by
     *                                     analyte_disambiguation.json.
     */
    public function __construct(
        public readonly string $id,
        public readonly ReportTestCategory $category,
        public readonly string $panel,
        public readonly bool $inOfficialPanel,
        public readonly string $name,
        public readonly string $nameAr,
        public readonly string $shortName,
        public readonly array $safeAliases,
        public readonly bool $derived,
        public readonly ?string $formula,
        public readonly ?string $classifier,
    ) {}
}
