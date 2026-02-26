<?php

namespace Tests\Unit\Services\Domain\Product;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\ProductFilterService;
use HiEvents\Services\Domain\Product\ProductPriceService;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use HiEvents\Services\Domain\Order\OrderPlatformFeePassThroughService;
use HiEvents\Services\Domain\Tax\DTO\TaxCalculationResponse;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class ProductFilterServiceTest extends TestCase
{
    private ProductFilterService $service;
    private TaxAndFeeCalculationService $taxCalculationService;
    private ProductPriceService $productPriceService;
    private AvailableProductQuantitiesFetchService $fetchAvailableProductQuantitiesService;
    private OrderPlatformFeePassThroughService $platformFeeService;
    private AccountRepositoryInterface $accountRepository;
    private EventRepositoryInterface $eventRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taxCalculationService = m::mock(TaxAndFeeCalculationService::class);
        $this->productPriceService = m::mock(ProductPriceService::class);
        $this->fetchAvailableProductQuantitiesService = m::mock(AvailableProductQuantitiesFetchService::class);
        $this->platformFeeService = m::mock(OrderPlatformFeePassThroughService::class);
        $this->accountRepository = m::mock(AccountRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);

        $this->service = new ProductFilterService(
            $this->taxCalculationService,
            $this->productPriceService,
            $this->fetchAvailableProductQuantitiesService,
            $this->platformFeeService,
            $this->accountRepository,
            $this->eventRepository,
        );
    }

    private function makeProduct(string $visibility, int $id = 1, int $categoryId = 1): ProductDomainObject
    {
        $price = (new ProductPriceDomainObject())
            ->setId(1)
            ->setProductId($id)
            ->setPrice(10.0)
            ->setInitialQuantityAvailable(100)
            ->setQuantityAvailable(100)
            ->setQuantitySold(0)
            ->setIsHidden(false);

        $product = (new ProductDomainObject())
            ->setId($id)
            ->setEventId(1)
            ->setProductCategoryId($categoryId)
            ->setTitle('Test Product')
            ->setOrder(1)
            ->setType('PAID')
            ->setProductType('TICKET')
            ->setAffiliateLinkVisibility($visibility)
            ->setIsHiddenWithoutPromoCode(false)
            ->setHideBeforeSaleStartDate(false)
            ->setHideAfterSaleEndDate(false)
            ->setHideWhenSoldOut(false)
            ->setIsHidden(false)
            ->setProductPrices(collect([$price]));

        return $product;
    }

    private function makeCategory(Collection $products, int $id = 1): ProductCategoryDomainObject
    {
        $category = new ProductCategoryDomainObject();
        $category->setId($id);
        $category->setName('General');
        $category->setIsHidden(false);
        $category->setProducts($products);

        return $category;
    }

    private function setupStandardMocks(): void
    {
        $accountConfig = m::mock(AccountConfigurationDomainObject::class);
        $accountConfig->shouldReceive('getHideTaxFromBuyers')->andReturn(false);
        $accountConfig->shouldReceive('getHideFeesFromBuyers')->andReturn(false);

        $account = m::mock(AccountDomainObject::class);
        $account->shouldReceive('getConfiguration')->andReturn($accountConfig);

        $this->accountRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->accountRepository->shouldReceive('findByEventId')->andReturn($account);

        $eventSettings = m::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getHideTaxFromBuyers')->andReturn(false);
        $eventSettings->shouldReceive('getHideFeesFromBuyers')->andReturn(false);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);
        $event->shouldReceive('getCurrency')->andReturn('USD');

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->andReturn($event);

        $quantitiesResponse = new AvailableProductQuantitiesResponseDTO(
            productQuantities: collect([]),
        );
        $this->fetchAvailableProductQuantitiesService
            ->shouldReceive('getAvailableProductQuantities')
            ->andReturn($quantitiesResponse);

        $taxResponse = new TaxCalculationResponse(feeTotal: 0.0, taxTotal: 0.0, rollUp: []);
        $this->taxCalculationService
            ->shouldReceive('calculateTaxAndFeesForProductPrice')
            ->andReturn($taxResponse);

        $this->platformFeeService
            ->shouldReceive('calculatePlatformFee')
            ->andReturn(0.0);
    }

    /**
     * @dataProvider affiliateVisibilityDataProvider
     */
    public function testAffiliateLinkVisibility(
        string $visibility,
        bool   $hasAffiliateCode,
        bool   $expectVisible,
    ): void
    {
        $this->setupStandardMocks();

        $product = $this->makeProduct($visibility);
        $category = $this->makeCategory(collect([$product]));

        $result = $this->service->filter(
            productsCategories: collect([$category]),
            hideSoldOutProducts: true,
            hasAffiliateCode: $hasAffiliateCode,
        );

        $resultProducts = $result->first()->getProducts();
        $visibleCount = $resultProducts->count();

        if ($expectVisible) {
            $this->assertSame(1, $visibleCount, "Product should be visible with visibility=$visibility, hasAffiliateCode=$hasAffiliateCode");
        } else {
            $this->assertSame(0, $visibleCount, "Product should be hidden with visibility=$visibility, hasAffiliateCode=$hasAffiliateCode");
        }
    }

    public static function affiliateVisibilityDataProvider(): array
    {
        return [
            'SHOW_ALWAYS without affiliate code'  => ['SHOW_ALWAYS', false, true],
            'SHOW_ALWAYS with affiliate code'     => ['SHOW_ALWAYS', true, true],
            'AFFILIATE_ONLY without affiliate code' => ['AFFILIATE_ONLY', false, false],
            'AFFILIATE_ONLY with affiliate code'  => ['AFFILIATE_ONLY', true, true],
            'NORMAL_ONLY without affiliate code'  => ['NORMAL_ONLY', false, true],
            'NORMAL_ONLY with affiliate code'     => ['NORMAL_ONLY', true, false],
        ];
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
