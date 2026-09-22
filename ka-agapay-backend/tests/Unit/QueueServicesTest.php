<?php

namespace Tests\Unit;

use App\Support\QueueServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * The queue's service catalogue.
 *
 * These were ten strings repeated across eight files — the validation rules,
 * the ticket-prefix map, two label maps, the controller's allow-list and the
 * admin's copy. The risk in consolidating them is not that the list is wrong
 * today; it is that a stored code stops resolving. A ticket whose service has
 * been retired must still print its own name and keep its number prefix, or a
 * patient is holding a ticket the system no longer understands.
 */
class QueueServicesTest extends TestCase
{
    /**
     * Codes and prefixes that existed before the table did.
     *
     * queue_tickets.service_type holds these, and ticket numbers carry the
     * prefixes, so both are effectively permanent. Written out literally
     * rather than read from the catalogue: the point is to fail if it changes.
     */
    private const ORIGINAL = [
        'opd_consultation' => 'OPD',
        'prenatal_checkup' => 'PRE',
        'immunization' => 'IMM',
        'family_planning' => 'FP',
        'tb_dots' => 'TB',
        'laboratory' => 'LAB',
        'dental' => 'DEN',
        'emergency' => 'ER',
        'medicine_release' => 'MED',
        'bhw_assisted' => 'BHW',
    ];

    #[Test]
    #[TestDox('every service that existed before the catalogue is still offered')]
    public function original_services_survive(): void
    {
        $codes = QueueServices::codes();

        foreach (array_keys(self::ORIGINAL) as $code) {
            $this->assertContains(
                $code,
                $codes,
                "The service '{$code}' is no longer offered. Tickets already "
                . 'issued for it would stop validating, and staff could not '
                . 'queue it. Switch a service off instead of removing it.'
            );
        }
    }

    #[Test]
    #[TestDox('ticket prefixes have not changed')]
    public function ticket_prefixes_are_stable(): void
    {
        foreach (self::ORIGINAL as $code => $prefix) {
            $this->assertSame(
                $prefix,
                QueueServices::prefix($code),
                "The ticket prefix for '{$code}' changed. Patients are holding "
                . 'tickets with the old one, and the daily number series is '
                . 'keyed on it.'
            );
        }
    }

    #[Test]
    #[TestDox('an unknown code still resolves to a readable name and prefix')]
    public function unknown_codes_degrade_readably(): void
    {
        // A code from a hand-edited row, or one retired before this catalogue
        // existed. It must not produce a blank ticket.
        $this->assertSame(
            'Animal Bite Treatment',
            QueueServices::label('animal_bite_treatment')
        );

        $this->assertSame('ANI', QueueServices::prefix('animal_bite_treatment'));
        $this->assertNotSame('', QueueServices::prefix('???'));
    }

    #[Test]
    #[TestDox('the validation list is built from the active services')]
    public function validation_list_matches_the_codes(): void
    {
        $this->assertSame(
            implode(',', QueueServices::codes()),
            QueueServices::validationList()
        );

        $this->assertStringContainsString(
            QueueServices::DEFAULT_CODE,
            QueueServices::validationList(),
            'The default service must always be accepted, or a walk-in with no '
            . 'stated service cannot be queued at all.'
        );
    }

    #[Test]
    #[TestDox('the default service is always active')]
    public function default_service_is_active(): void
    {
        $this->assertTrue(QueueServices::isActive(QueueServices::DEFAULT_CODE));
    }
}
