<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogInventory\Model\Stock;

use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemCollectionInterfaceFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface as StockItemRepositoryInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item as StockItemResource;
use Magento\CatalogInventory\Model\Spi\StockStateProviderInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\MapperFactory;
use Magento\Framework\DB\QueryBuilderFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Class StockItemRepository
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StockItemRepository implements StockItemRepositoryInterface
{
    /**
     * @var StockConfigurationInterface
     */
    protected $stockConfiguration;

    /**
     * @var StockStateProviderInterface
     */
    protected $stockStateProvider;

    /**
     * @var StockItemResource
     */
    protected $resource;

    /**
     * @var StockItemInterfaceFactory
     */
    protected $stockItemFactory;

    /**
     * @var StockItemCollectionInterfaceFactory
     */
    protected $stockItemCollectionFactory;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var QueryBuilderFactory
     */
    protected $queryBuilderFactory;

    /**
     * @var MapperFactory
     */
    protected $mapperFactory;

    /**
     * @var TimezoneInterface
     */
    protected $localeDate;

    /**
     * @var Processor
     */
    protected $indexProcessor;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var StockRegistryStorage
     */
    protected $stockRegistryStorage;

    /** @var  ProductCollectionFactory */
    protected $productCollectionFactory;

    /**
     * @param StockConfigurationInterface $stockConfiguration
     * @param StockStateProviderInterface $stockStateProvider
     * @param StockItemResource $resource
     * @param StockItemInterfaceFactory $stockItemFactory
     * @param StockItemCollectionInterfaceFactory $stockItemCollectionFactory
     * @param ProductFactory $productFactory
     * @param QueryBuilderFactory $queryBuilderFactory
     * @param MapperFactory $mapperFactory
     * @param TimezoneInterface $localeDate
     * @param Processor $indexProcessor
     * @param DateTime $dateTime
     * @param ProductCollectionFactory|null $productCollectionFactory
     * @param StockRegistryStorage|null $stockRegistryStorage
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @throws \RuntimeException
     */
    public function __construct(
        StockConfigurationInterface $stockConfiguration,
        StockStateProviderInterface $stockStateProvider,
        StockItemResource $resource,
        StockItemInterfaceFactory $stockItemFactory,
        StockItemCollectionInterfaceFactory $stockItemCollectionFactory,
        ProductFactory $productFactory,
        QueryBuilderFactory $queryBuilderFactory,
        MapperFactory $mapperFactory,
        TimezoneInterface $localeDate,
        Processor $indexProcessor,
        DateTime $dateTime,
        ProductCollectionFactory $productCollectionFactory = null,
        StockRegistryStorage $stockRegistryStorage = null
    ) {
        $this->stockConfiguration = $stockConfiguration;
        $this->stockStateProvider = $stockStateProvider;
        $this->resource = $resource;
        $this->stockItemFactory = $stockItemFactory;
        $this->stockItemCollectionFactory = $stockItemCollectionFactory;
        $this->productFactory = $productFactory;
        $this->queryBuilderFactory = $queryBuilderFactory;
        $this->mapperFactory = $mapperFactory;
        $this->localeDate = $localeDate;
        $this->indexProcessor = $indexProcessor;
        $this->dateTime = $dateTime;
        if (null === $productCollectionFactory) {
            $productCollectionFactory = ObjectManager::getInstance()->get(ProductCollectionFactory::class);
        }
        $this->productCollectionFactory = $productCollectionFactory;
        if (null === $stockRegistryStorage) {
            $stockRegistryStorage = ObjectManager::getInstance()->get(StockRegistryStorage::class);
        }
        $this->stockRegistryStorage = $stockRegistryStorage;
    }

    /**
     * @inheritdoc
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(\Magento\CatalogInventory\Api\Data\StockItemInterface $stockItem)
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $this->productCollectionFactory->create()
                ->setFlag('has_stock_status_filter')
                ->addIdFilter($stockItem->getProductId())
                ->addFieldToSelect('type_id')
                ->getFirstItem();

            if (!$product->getId()) {
                return $stockItem;
            }
            $typeId = $product->getTypeId() ?: $product->getTypeInstance()->getTypeId();
            $isQty = $this->stockConfiguration->isQty($typeId);
            if ($isQty) {
                $isInStock = $this->stockStateProvider->verifyStock($stockItem);
                if ($stockItem->getManageStock() && !$isInStock) {
                    $stockItem->setIsInStock(false)->setStockStatusChangedAutomaticallyFlag(true);
                }
                // if qty is below notify qty, update the low stock date to today date otherwise set null
                $stockItem->setLowStockDate(null);
                if ($this->stockStateProvider->verifyNotification($stockItem)) {
                    $stockItem->setLowStockDate($this->dateTime->gmtDate());
                }
                $stockItem->setStockStatusChangedAuto(0);
                if ($stockItem->hasStockStatusChangedAutomaticallyFlag()) {
                    $stockItem->setStockStatusChangedAuto((int)$stockItem->getStockStatusChangedAutomaticallyFlag());
                }
            } else {
                $stockItem->setQty(0);
            }

            $stockItem->setWebsiteId($stockItem->getWebsiteId());
            $stockItem->setStockId($stockItem->getStockId());

            $this->resource->save($stockItem);

            $this->indexProcessor->reindexRow($stockItem->getProductId());
            $this->stockRegistryStorage->removeStockItem($stockItem->getProductId());
            $this->stockRegistryStorage->removeStockStatus($stockItem->getProductId());
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__('Unable to save Stock Item'), $exception);
        }
        return $stockItem;
    }

    /**
     * @inheritdoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get($stockItemId)
    {
        $stockItem = $this->stockItemFactory->create();
        $this->resource->load($stockItem, $stockItemId);
        if (!$stockItem->getItemId()) {
            throw new NoSuchEntityException(__('Stock Item with id "%1" does not exist.', $stockItemId));
        }
        return $stockItem;
    }

    /**
     * @inheritdoc
     */
    public function getList(\Magento\CatalogInventory\Api\StockItemCriteriaInterface $criteria)
    {
        $queryBuilder = $this->queryBuilderFactory->create();
        $queryBuilder->setCriteria($criteria);
        $queryBuilder->setResource($this->resource);
        $query = $queryBuilder->create();
        $collection = $this->stockItemCollectionFactory->create(['query' => $query]);
        return $collection;
    }

    /**
     * @inheritdoc
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(StockItemInterface $stockItem)
    {
        try {
            $this->resource->delete($stockItem);
            $this->stockRegistryStorage->removeStockItem($stockItem->getProductId());
            $this->stockRegistryStorage->removeStockStatus($stockItem->getProductId());
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('Unable to remove Stock Item with id "%1"', $stockItem->getItemId()),
                $exception
            );
        }
        return true;
    }

    /**
     * @inheritdoc
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteById($id)
    {
        try {
            $stockItem = $this->get($id);
            $this->delete($stockItem);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('Unable to remove Stock Item with id "%1"', $id),
                $exception
            );
        }
        return true;
    }
}
