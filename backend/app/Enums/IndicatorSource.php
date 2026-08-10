<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an individual indicator came from.
 *
 * This column exists for honesty, and it is a deliberate design choice rather
 * than bookkeeping. The `ml-endpoint` skill notes that DistilBERT explainability
 * at request time is impractical — attention and SHAP are too slow for a
 * three-second budget — so evidence strings come from a curated pattern layer
 * running alongside the model, while the model contributes the score. That is a
 * hybrid, and the skill requires it be documented as one rather than presented
 * as model interpretability.
 *
 * Recording the source per indicator makes that distinction structural instead
 * of a paragraph in the report: a query can show exactly which evidence the
 * model produced and which a regex did.
 */
enum IndicatorSource: string
{
    /** A feature importance or attribution from the classifier itself. */
    case Model = 'model';

    /** A curated keyword or structural pattern match, not model output. */
    case Pattern = 'pattern';

    /** A third-party reputation hit (Google Safe Browsing, VirusTotal). */
    case ThreatIntel = 'threat_intel';
}
