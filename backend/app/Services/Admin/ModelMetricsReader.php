<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Support\Facades\File;

/**
 * Reads model performance from the committed `metrics.json` artefacts.
 *
 * The dashboard does **not** compute or store model metrics of its own. Those
 * files are written by the training scripts and committed alongside the code
 * (ADR 0001), so reading them is what guarantees a number on this screen and a
 * number in Chapter 4 cannot drift apart.
 *
 * It also carries the `REPORTING_QUALIFIER` through to the UI. A metric shown
 * without its caveat is how "F1 on a 528-message collected corpus" quietly
 * becomes "ScamSense is 97% accurate" in someone's slides.
 */
class ModelMetricsReader
{
    public function __construct(private ?string $modelsPath = null) {}

    private function path(): string
    {
        return $this->modelsPath
            ?? (string) config('scamsense.models_path', base_path('../ml-service/models'));
    }

    /**
     * Every artefact that has a metrics.json, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $root = $this->path();

        if (! File::isDirectory($root)) {
            return [];
        }

        $models = [];

        foreach (File::directories($root) as $directory) {
            $metrics = $this->read($directory.DIRECTORY_SEPARATOR.'metrics.json');

            if ($metrics === null) {
                continue;
            }

            $test = (array) ($metrics['test'] ?? []);

            $models[] = [
                'name' => basename($directory),
                'kind' => str_starts_with(basename($directory), 'rf-url') ? 'url' : 'message',
                'trained_at' => $metrics['trained_at'] ?? null,
                'split_strategy' => $metrics['split_strategy'] ?? null,
                // Either key depending on the task; both are the positive class.
                'f1' => $test['f1_phishing'] ?? $test['f1_scam'] ?? null,
                'precision' => $test['precision_phishing'] ?? $test['precision_scam'] ?? null,
                'recall' => $test['recall_phishing'] ?? $test['recall_scam'] ?? null,
                'roc_auc' => $test['roc_auc'] ?? null,
                'threshold' => $test['threshold'] ?? null,
                'support' => $test['support'] ?? null,
                // Carried through so the caveat travels with the figure.
                'qualifier' => $metrics['REPORTING_QUALIFIER'] ?? null,
                'status' => $metrics['STATUS'] ?? null,
                'corpus' => $metrics['corpus'] ?? null,
                'is_shipped' => in_array(
                    basename($directory),
                    [
                        (string) config('scamsense.shipped_models.url'),
                        (string) config('scamsense.shipped_models.message'),
                    ],
                    true,
                ),
            ];
        }

        usort($models, static fn (array $a, array $b): int => (string) ($b['trained_at'] ?? '') <=> (string) ($a['trained_at'] ?? ''));

        return $models;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $file): ?array
    {
        if (! File::exists($file)) {
            return null;
        }

        $decoded = json_decode((string) File::get($file), true);

        return is_array($decoded) ? $decoded : null;
    }
}
