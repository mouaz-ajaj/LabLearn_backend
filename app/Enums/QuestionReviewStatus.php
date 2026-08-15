<?php

namespace App\Enums;

// Tracked as question-bank metadata for a future content/review workflow.
// GeneratedPendingReview is honest labeling for KBS-generated content (Phase 3B.4):
// deterministically derived from real KBS structure, but never medically reviewed by
// a human. Selection filtering by this status is opt-in via
// config('quiz.require_approved_general_questions') - see FinalizeQuizSession and
// backend/docs/general-question-bank.md.
enum QuestionReviewStatus: string
{
    case Draft = 'DRAFT';
    case GeneratedPendingReview = 'GENERATED_PENDING_REVIEW';
    case Approved = 'APPROVED';
}
