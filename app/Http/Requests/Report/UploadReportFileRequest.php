<?php

namespace App\Http\Requests\Report;

use App\Enums\ReportSourceType;
use App\Models\Report;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\Response;

class UploadReportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $extensions = config('ocr.allowed_extensions');
        $mimeTypes = config('ocr.allowed_mime_types');

        return [
            'file' => [
                'required',
                File::types($extensions)->max((int) config('ocr.max_upload_kilobytes')),
                'extensions:'.implode(',', $extensions),
                'mimetypes:'.implode(',', $mimeTypes),
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('file')) {
                return;
            }

            $file = $this->file('file');
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() === 0) {
                $validator->errors()->add('file', 'The uploaded report file is invalid.');

                return;
            }

            $signatureType = $this->signatureType($file);
            $report = $this->route('report');

            if ($signatureType === null || ($report instanceof Report && ! $this->matchesSourceType($report, $signatureType))) {
                $validator->errors()->add('file', 'The uploaded file content does not match the selected source type.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        $file = $this->file('file');
        $isTooLarge = $file instanceof UploadedFile
            && $file->getSize() > ((int) config('ocr.max_upload_kilobytes') * 1024);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $isTooLarge ? 'The report file is too large.' : 'The report file is invalid.',
            'error_code' => $isTooLarge ? 'REPORT_FILE_TOO_LARGE' : 'REPORT_FILE_INVALID',
            'errors' => $validator->errors()->toArray(),
        ], $isTooLarge ? Response::HTTP_REQUEST_ENTITY_TOO_LARGE : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function signatureType(UploadedFile $file): ?string
    {
        $signature = file_get_contents($file->getRealPath(), false, null, 0, 8);

        if (is_string($signature) && str_starts_with($signature, '%PDF-')) {
            return 'pdf';
        }

        if (is_string($signature) && (str_starts_with($signature, "\x89PNG\r\n\x1a\n") || str_starts_with($signature, "\xff\xd8\xff"))) {
            return 'image';
        }

        return null;
    }

    private function matchesSourceType(Report $report, string $signatureType): bool
    {
        return match ($report->source_type) {
            ReportSourceType::Pdf => $signatureType === 'pdf',
            ReportSourceType::Image, ReportSourceType::Camera => $signatureType === 'image',
        };
    }
}
