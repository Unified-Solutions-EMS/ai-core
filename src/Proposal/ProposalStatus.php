<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

enum ProposalStatus: string
{
    case Drafted = 'drafted';
    case Refined = 'refined';
    case Approved = 'approved';
    case Executing = 'executing';
    case Executed = 'executed';
    case Verified = 'verified';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Drafted, self::Refined => [self::Refined, self::Approved, self::Rejected],
            self::Approved => [self::Refined, self::Executing, self::Rejected],
            self::Executing => [self::Executed, self::Failed],
            self::Executed => [self::Verified],
            self::Verified, self::Failed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }
}
