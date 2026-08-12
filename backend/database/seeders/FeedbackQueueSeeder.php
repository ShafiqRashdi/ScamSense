<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\Verdict;
use App\Services\RedactorService;
use Illuminate\Database\Seeder;

/**
 * A realistic moderation queue, for development and for report screenshots.
 *
 * Everything here lands at `pending`. There is deliberately no seeded approval
 * and no seeded training sample: a seeder that could create one would be a code
 * path around the moderation gate, and the gate would reject it anyway (see
 * TrainingSample and ADR 0008). The screenshot shows work waiting for a human,
 * which is the honest picture.
 *
 * The comments are the kinds of thing real reporters write — some correct, some
 * mistaken, a couple useless. A queue of uniformly sensible reports would
 * misrepresent what moderation is actually like.
 */
class FeedbackQueueSeeder extends Seeder
{
    public function run(RedactorService $redactor): void
    {
        $verdicts = Verdict::query()
            ->where('is_partial', false)
            ->with('submission')
            ->get();

        if ($verdicts->isEmpty()) {
            $this->command?->warn('No verdicts to attach feedback to; run SampleSubmissionSeeder first.');

            return;
        }

        $reports = $this->reports();
        $created = 0;

        foreach ($verdicts as $index => $verdict) {
            if ($index >= count($reports)) {
                break;
            }

            [$agrees, $comment, $daysAgo] = $reports[$index];

            Feedback::query()->create([
                'verdict_id' => $verdict->id,
                'agrees' => $agrees,
                // Redacted on the way in, exactly as the live endpoint does.
                'comment' => $comment === null ? null : $redactor->redactToString($comment),
                'status' => FeedbackStatus::Pending,
                'ip_hash' => hash('sha256', fake()->ipv4()),
                'created_at' => now()->subDays($daysAgo)->subHours(random_int(0, 23)),
                'updated_at' => now()->subDays($daysAgo),
            ]);

            $created++;
        }

        $this->command?->info("Seeded {$created} pending feedback items. None are approved.");
    }

    /**
     * @return list<array{0: bool, 1: string|null, 2: int}>
     */
    private function reports(): array
    {
        return [
            // Disputes worth taking seriously — a moderator would likely agree.
            [false, 'Ini mesej rasmi daripada bank saya. Saya memang ada akaun dengan mereka dan nombor ini betul.', 6],
            [false, 'This is my company internal portal, not a scam. We use this link every day for staff login.', 5],
            [false, 'Saya dapat mesej ini selepas saya sendiri mohon pinjaman. Bukan penipuan.', 5],

            // Confirmations, the common case.
            [true, null, 4],
            [true, 'Betul, kawan saya hampir kena. Nasib baik semak dulu.', 4],
            [true, 'Sudah report kat Semak Mule juga.', 3],
            [true, null, 3],
            [true, 'Nombor sama dengan yang saya nampak dalam kumpulan WhatsApp keluarga saya.', 3],

            // Disputes a moderator would probably reject — the reporter is
            // mistaken, which is exactly why review exists.
            [false, 'Saya rasa ini betul sebab ada nama Maybank dalam link tu.', 2],
            [false, 'my friend said this website is safe he used it before', 2],

            // Low-quality reports. A real queue has these.
            [true, 'ok', 2],
            [false, '???', 1],
            [true, 'Terima kasih ScamSense, sangat membantu!', 1],

            // A report containing PII, so the screenshot shows redaction
            // working inside the moderation view too.
            [false, 'Ini betul, saya dah sahkan. Hubungi saya di 012-345 6789 kalau perlu bukti.', 1],

            [true, 'Dapat mesej ini tiga kali minggu ini daripada nombor berbeza.', 0],
            [true, null, 0],
        ];
    }
}
