<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\EventStatisticDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetEventsDTO;
use Illuminate\Pagination\LengthAwarePaginator;

class GetEventsHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
    )
    {
    }

    public function handle(GetEventsDTO $dto): LengthAwarePaginator
    {
        $where = [
            'account_id' => $dto->accountId,
        ];

        if ($dto->organizerIds !== null) {
            $where[] = static function (\Illuminate\Database\Eloquent\Builder $builder) use ($dto) {
                $builder->whereIn('organizer_id', $dto->organizerIds);
            };
        }

        return $this->eventRepository
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->loadRelation(new Relationship(EventStatisticDomainObject::class))
            ->loadRelation(new Relationship(
                domainObject: ProductDomainObject::class,
                nested: [
                    new Relationship(ProductPriceDomainObject::class),
                ],
            ))
            ->loadRelation(new Relationship(
                domainObject: OrganizerDomainObject::class,
                name: 'organizer',
            ))
            ->findEvents(
                where: $where,
                params: $dto->queryParams
            );
    }
}
