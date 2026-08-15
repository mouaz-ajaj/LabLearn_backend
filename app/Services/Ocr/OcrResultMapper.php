<?php

namespace App\Services\Ocr;

use Illuminate\Support\Arr;

class OcrResultMapper
{
    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    public function rows(array $response): array
    {
        $tests = Arr::get($response, 'data.tests', Arr::get($response, 'report.tests', []));
        if (! is_array($tests)) {
            return [];
        }

        $rows = [];
        foreach ($tests as $test) {
            if (! is_array($test)) {
                continue;
            }

            $rows[] = [
                // Raw fields: exactly what the report/OCR originally produced. These are
                // never replaced by, or mixed with, OCR's interpreted/normalized reading.
                'raw_label' => $this->stringValue(
                    Arr::get($test, 'original_test_name', Arr::get($test, 'original_name', Arr::get($test, 'name'))),
                ),
                'raw_value' => $this->stringValue(
                    Arr::get($test, 'value.raw', Arr::get($test, 'value_raw', Arr::get($test, 'value'))),
                ),
                'raw_unit' => $this->stringValue(
                    Arr::get($test, 'unit.raw', Arr::get($test, 'unit.normalized', Arr::get($test, 'unit'))),
                ),
                'raw_reference' => $this->stringValue(
                    Arr::get($test, 'reference_range.raw', Arr::get($test, 'normal_range.raw', Arr::get($test, 'reference_range'))),
                ),
                // OCR's interpretation: a candidate, not an authority. Preserved
                // separately so it is available downstream instead of being discarded
                // after storage; KBS independently re-validates it before ever using it.
                'ocr_canonical_name' => $this->stringValue(Arr::get($test, 'canonical_name')),
                'ocr_test_code' => $this->stringValue(Arr::get($test, 'test_code')),
                'ocr_kbs_test_id' => $this->stringValue(Arr::get($test, 'kbs_test_id')),
                'ocr_normalized_value' => $this->stringValue(Arr::get($test, 'value.normalized')),
                'ocr_normalized_unit' => $this->stringValue(Arr::get($test, 'unit.normalized')),
                'ocr_normalized_reference' => $this->stringValue(Arr::get($test, 'reference_range.normalized')),
                'page' => $this->positiveInteger(Arr::get($test, 'page_number', Arr::get($test, 'page'))),
                'bbox_json' => $this->boundingBox($test),
                'confidence' => $this->confidence($test),
                'raw_row_json' => $test,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $test */
    private function boundingBox(array $test): ?array
    {
        $boundingBox = Arr::get($test, 'bbox', Arr::get($test, 'bounding_box', Arr::get($test, 'location.bbox')));

        return is_array($boundingBox) && array_is_list($boundingBox) ? $boundingBox : null;
    }

    /** @param array<string, mixed> $test */
    private function confidence(array $test): ?float
    {
        $value = Arr::get(
            $test,
            'confidence.result_value.calibrated',
            Arr::get($test, 'confidence.value.calibrated', Arr::get($test, 'confidence')),
        );

        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }
}
