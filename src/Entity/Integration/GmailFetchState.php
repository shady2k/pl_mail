<?php

declare(strict_types=1);

namespace App\Entity\Integration;

use App\Entity\Mail\Account;
use Doctrine\ORM\Mapping as ORM;

/** DBAL owns the updates; this mapping keeps schema tooling aware of them. */
#[ORM\Entity]
#[ORM\Table(name: 'gmail_fetch_state')]
class GmailFetchState
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    #[ORM\Id, ORM\Column(length: 255)]
    public string $gmailId;

    #[ORM\Column(type: 'decimal', precision: 30, scale: 0)]
    public string $queuedRevision;

    #[ORM\Column(type: 'decimal', precision: 30, scale: 0, nullable: true)]
    public ?string $completedRevision = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $reservedUntil;
}
