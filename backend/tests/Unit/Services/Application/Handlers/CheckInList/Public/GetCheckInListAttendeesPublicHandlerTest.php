<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListAttendeesPublicHandler;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GetCheckInListAttendeesPublicHandlerTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private GetCheckInListAttendeesPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);

        $this->handler = new GetCheckInListAttendeesPublicHandler(
            $this->attendeeRepository,
            $this->checkInListRepository,
        );
    }

    public function testHandleThrowsNotFoundIfCheckInListMissing(): void
    {
        $this->checkInListRepository
            ->shouldReceive('loadRelation')->andReturnSelf()->times(2);
        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')->once()->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', QueryParamsDTO::fromArray([]));
    }

    public function testHandleThrowsCannotCheckInIfListExpiredByDefault(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->twice()->andReturn(now()->subMinute());

        $this->checkInListRepository
            ->shouldReceive('loadRelation')->andReturnSelf()->times(2);
        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')->once()->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', QueryParamsDTO::fromArray([]));
    }

    public function testHandleSkipsActiveValidationWhenEnforceActiveIsFalse(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        // If validation runs, these would be invoked. Asserting `never` proves the guard works.
        $checkInList->shouldReceive('getExpiresAt')->never();
        $checkInList->shouldReceive('getActivatesAt')->never();

        $this->checkInListRepository
            ->shouldReceive('loadRelation')->andReturnSelf()->times(2);
        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')->once()->andReturn($checkInList);

        $collection = m::mock(Collection::class);
        $collection->shouldReceive('transform')->once()->andReturnSelf();

        $paginator = m::mock(Paginator::class);
        $paginator->shouldReceive('getCollection')->once()->andReturn($collection);

        $this->attendeeRepository
            ->shouldReceive('loadRelation')->andReturnSelf()->times(2);
        $this->attendeeRepository
            ->shouldReceive('getAttendeesByCheckInShortId')->once()->andReturn($paginator);

        $result = $this->handler->handle('short-id', QueryParamsDTO::fromArray([]), enforceActive: false);

        $this->assertSame($paginator, $result);
    }
}
