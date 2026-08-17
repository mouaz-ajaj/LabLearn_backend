<?php

namespace App\Enums;

/**
 * Internal observability detail (logging/tests) distinguishing how a returned
 * explanation was produced. Never required reading by the frontend - the public API
 * response only ever exposes the simpler AiContextStatus (AVAILABLE|FALLBACK), the
 * same shape Phase 4C's ai_context already uses.
 */
enum AiExplanationSource: string
{
    case Cached = 'CACHED';
    case Generated = 'GENERATED';
    case Fallback = 'FALLBACK';
}
