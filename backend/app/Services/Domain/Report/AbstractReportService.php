<?php

namespace HiEvents\Services\Domain\Report;

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

abstract class AbstractReportService
{
    public function __construct(
        private readonly Repository                $cache,
        protected readonly DatabaseManager         $queryBuilder,
        private readonly EventRepositoryInterface  $eventRepository,
    )
    {
    }

    public function generateReport(
        int     $eventId,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        bool    $includeSensitive = false,
    ): Collection
    {
        $event = $this->eventRepository->findById($eventId);
        $timezone = $event->getTimezone();

        $endDate = $endDate
            ? $endDate->copy()->setTimezone($timezone)->endOfDay()
            : now($timezone)->endOfDay();

        $startDate = $startDate
            ? $startDate->copy()->setTimezone($timezone)->startOfDay()
            : $endDate->copy()->subDays(30)->startOfDay();

        $reportResults = $this->cache->remember(
            key: $this->getCacheKey($eventId, $startDate, $endDate, $includeSensitive),
            ttl: Carbon::now()->addSeconds(20),
            callback: function () use ($eventId, $startDate, $endDate, $includeSensitive) {
                $rows = collect($this->queryBuilder->select(
                    $this->getSqlQuery($startDate, $endDate),
                    [
                        'event_id' => $eventId,
                    ]
                ));

                return $this->postProcess($rows, $eventId, $startDate, $endDate, $includeSensitive);
            }
        );

        return collect($reportResults);
    }

    abstract protected function getSqlQuery(Carbon $startDate, Carbon $endDate): string;

    /**
     * Hook for reports to enrich their rows after the base query runs. Default is a no-op.
     * Sensitive (e.g. admin-only) data should only be added when $includeSensitive is true.
     */
    protected function postProcess(
        Collection $rows,
        int        $eventId,
        Carbon     $startDate,
        Carbon     $endDate,
        bool       $includeSensitive,
    ): Collection
    {
        return $rows;
    }

    protected function getCacheKey(int $eventId, ?Carbon $startDate, ?Carbon $endDate, bool $includeSensitive = false): string
    {
        $sensitiveSuffix = $includeSensitive ? '.sensitive' : '';

        return static::class . "$eventId.{$startDate?->toDateString()}.{$endDate?->toDateString()}$sensitiveSuffix";
    }
}
