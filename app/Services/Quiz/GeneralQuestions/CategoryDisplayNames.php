<?php

namespace App\Services\Quiz\GeneralQuestions;

use App\Enums\ReportTestCategory;

final class CategoryDisplayNames
{
    public static function en(ReportTestCategory $category): string
    {
        return match ($category) {
            ReportTestCategory::Cbc => 'CBC',
            ReportTestCategory::Diabetes => 'DIABETES',
            ReportTestCategory::LiverFunction => 'LIVER_FUNCTION',
        };
    }

    public static function ar(ReportTestCategory $category): string
    {
        return match ($category) {
            ReportTestCategory::Cbc => 'تعداد الدم الكامل',
            ReportTestCategory::Diabetes => 'السكري',
            ReportTestCategory::LiverFunction => 'وظائف الكبد',
        };
    }
}
