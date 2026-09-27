<?php
declare(strict_types=1);

namespace Modules\Calls\Domain;

use App\Contracts\CallProvider;

/**
 * Своя комната на сервере Jitsi (например, на том же узбекском сервере).
 * Имя комнаты не угадать: оно выводится из секрета приложения, сквада и
 * созвона, и у каждого созвона своё.
 */
final class JitsiRoom implements CallProvider
{
    public function __construct(private string $base, private string $secret)
    {
    }

    public function name(): string
    {
        return 'jitsi';
    }

    public function room(array $team, array $session): array
    {
        $room = 'L180-' . substr(hash_hmac('sha256', 'call|' . $team['id'] . '|' . $session['id'], $this->secret), 0, 20);
        return ['url' => rtrim($this->base, '/') . '/' . $room, 'how' => 'calls.how.jitsi'];
    }
}
