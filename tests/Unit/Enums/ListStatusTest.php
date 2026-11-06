<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ListStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ListStatusTest extends TestCase
{
    #[Test]
    public function it_has_all_required_statuses(): void
    {
        $statuses = ListStatus::cases();

        $this->assertContains(ListStatus::DRAFT, $statuses);
        $this->assertContains(ListStatus::PENDING, $statuses);
        $this->assertContains(ListStatus::PROCESSING, $statuses);
        $this->assertContains(ListStatus::READY, $statuses);
        $this->assertContains(ListStatus::FAILED, $statuses);
        $this->assertContains(ListStatus::IN_USE, $statuses);
        $this->assertContains(ListStatus::USED, $statuses);
        $this->assertContains(ListStatus::ARCHIVED, $statuses);
    }

    #[Test]
    public function it_provides_labels_for_all_statuses(): void
    {
        foreach (ListStatus::cases() as $status) {
            $this->assertIsString($status->label());
            $this->assertNotEmpty($status->label());
        }
    }

    #[Test]
    public function it_provides_colors_for_all_statuses(): void
    {
        foreach (ListStatus::cases() as $status) {
            $this->assertIsString($status->color());
            $this->assertNotEmpty($status->color());
        }
    }

    #[Test]
    public function ready_status_can_be_assigned(): void
    {
        $this->assertTrue(ListStatus::READY->canAssign());
    }

    #[Test]
    public function non_ready_statuses_cannot_be_assigned(): void
    {
        $this->assertFalse(ListStatus::DRAFT->canAssign());
        $this->assertFalse(ListStatus::PROCESSING->canAssign());
        $this->assertFalse(ListStatus::FAILED->canAssign());
        $this->assertFalse(ListStatus::IN_USE->canAssign());
        $this->assertFalse(ListStatus::USED->canAssign());
        $this->assertFalse(ListStatus::ARCHIVED->canAssign());
    }

    #[Test]
    public function ready_failed_used_can_be_archived(): void
    {
        $this->assertTrue(ListStatus::READY->canArchive());
        $this->assertTrue(ListStatus::FAILED->canArchive());
        $this->assertTrue(ListStatus::USED->canArchive());
    }

    #[Test]
    public function in_use_and_processing_cannot_be_archived(): void
    {
        $this->assertFalse(ListStatus::IN_USE->canArchive());
        $this->assertFalse(ListStatus::PROCESSING->canArchive());
    }

    #[Test]
    public function draft_and_failed_can_upload(): void
    {
        $this->assertTrue(ListStatus::DRAFT->canUpload());
        $this->assertTrue(ListStatus::PENDING->canUpload());
        $this->assertTrue(ListStatus::FAILED->canUpload());
    }

    #[Test]
    public function in_use_and_used_are_locked(): void
    {
        $this->assertTrue(ListStatus::IN_USE->isLocked());
        $this->assertTrue(ListStatus::USED->isLocked());
    }

    #[Test]
    public function archived_is_final_state(): void
    {
        $this->assertTrue(ListStatus::ARCHIVED->isFinal());
    }

    #[Test]
    public function non_archived_are_not_final(): void
    {
        $this->assertFalse(ListStatus::READY->isFinal());
        $this->assertFalse(ListStatus::DRAFT->isFinal());
    }

    #[Test]
    public function it_converts_to_array(): void
    {
        $array = ListStatus::toArray();

        $this->assertIsArray($array);
        $this->assertCount(8, $array);
        $this->assertArrayHasKey('draft', $array);
        $this->assertArrayHasKey('ready', $array);
        $this->assertEquals('Draft', $array['draft']);
        $this->assertEquals('Ready', $array['ready']);
    }
}
