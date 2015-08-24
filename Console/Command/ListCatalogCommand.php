<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Session\CatalogList\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Magento\Framework\App\State;
use Magento\Framework\DB\Select;

/**
 * Command for installing Sample Data
 */
class ListCatalogCommand extends Command
{

    /**
     * Type option key
     */
    const ATTRIBUTES_OPTION = 'attributes';

    /**
     * Type option key
     */
    const INVENTORY_FIELDS_OPTION = 'inventory_fields';

    /**
     * Website argument keu
     */
    const WEBSITE_ARGUMENT = 'website';

    /**
     * TYPE_FILTER_OPTION
     */
    const TYPE_FILTER_OPTION = 'type';

    /**
     * Order
     */
    const ORDER_OPTION = 'order';

    /**
     * @var \Magento\Store\Model\Resource\Website\CollectionFactory
     */
    protected $_websitesFactory;

    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $_productFactory;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    protected $moduleManager;

    /**
     * @var array
     */
    protected $_attributes;

    /**
     * @var array
     */
    protected $_inventoryFields;

    /**
     * @var array
     */
    protected $_typeFilters;


    /**
     * Print websites flag
     * @var bool
     */
    protected $_printWebsites;


    /**
     * \Magento\Store\Model\Resource\Website\CollectionFactory $websitesFactory,
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Framework\Module\Manager $moduleManager
     */
    public function __construct(
        \Magento\Store\Model\Resource\Website\CollectionFactory $websitesFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\Module\Manager $moduleManager
    ) {

        $this->_websitesFactory = $websitesFactory;
        $this->_productFactory = $productFactory;
        $this->moduleManager = $moduleManager;
        $this->_printWebsites = false;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('session:catalog:list')
            ->setDescription('List all your products')
            ->setDefinition([
                new InputOption(
                    self::ATTRIBUTES_OPTION,
                    'a',
                    InputOption::VALUE_REQUIRED,
                    'Attribute list, comma separated',
                    'name,price'
                ),
                new InputOption(
                    self::INVENTORY_FIELDS_OPTION,
                    'i',
                    InputOption::VALUE_OPTIONAL,
                    'Fields from inventory item',
                    'qty'
                ),
                new InputOption(
                    self::TYPE_FILTER_OPTION,
                    't',
                    InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                    'Filter by type'
                ),
                new InputOption(
                    self::ORDER_OPTION,
                    'o',
                    InputOption::VALUE_OPTIONAL,
                    'Order by'
                ),
                new InputArgument(
                    self::WEBSITE_ARGUMENT,
                    InputArgument::OPTIONAL,
                    'Print assigned websites'
                ),
            ]);

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $attributes = $input->getOption(self::ATTRIBUTES_OPTION);
        $this->_attributes = array_map('trim', explode(',', $attributes));

        $inventoryFields = $input->getOption(self::INVENTORY_FIELDS_OPTION);
        $this->_inventoryFields = array_map('trim', explode(',', $inventoryFields));

        if ($input->getArgument(self::WEBSITE_ARGUMENT)) {
            $this->_printWebsites = true;
        }
        if ($typeFilters = $input->getOption(self::TYPE_FILTER_OPTION)) {
            $this->_typeFilters = $typeFilters;
        }
    }

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|int null or 0 if everything went fine, or an error code
     *
     * @throws \LogicException When this abstract method is not implemented
     *
     * @see setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $collection = $this->_productFactory->create()->getCollection();
        $collection->addAttributeToSelect($this->_attributes);
        $this->_joinInventoryFields($collection);

        if ($this->_printWebsites) {
            $collection->addWebsiteNamesToResult();
        }

        if ($this->_typeFilters) {
            $collection->addFieldToFilter('type_id', array('in' => $this->_typeFilters));
        }

        if ($order = $input->getOption(self::ORDER_OPTION)) {
            $collection->setOrder($order, Select::SQL_ASC);
        }

        $this->_printProducts($collection);
    }

    /**
     * @param \Magento\Catalog\Model\Resource\Product\Collection $collection
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _joinInventoryFields(\Magento\Catalog\Model\Resource\Product\Collection $collection)
    {
        if (!empty($this->_inventoryFields) && $this->moduleManager->isEnabled('Magento_CatalogInventory')) {

            foreach($this->_inventoryFields as $field) {
                $collection->joinField(
                    $field,
                    'cataloginventory_stock_item',
                    $field,
                    'product_id=entity_id',
                    '{{table}}.stock_id=1',
                    'left'
                );
            }
        }
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     */
    protected function _printProducts(\Magento\Catalog\Model\Resource\Product\Collection $productCollection)
    {
        /** @var \Magento\Catalog\Model\Product $product */
        foreach($productCollection as $product) {
            $this->_printFields($product, $this->_attributes);
            $this->_printFields($product, $this->_inventoryFields);
            $this->_printAssignedWebsites($product);
            echo "\n";
        }
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     * @param $fields string
     */
    protected function _printFields(\Magento\Catalog\Model\Product $product, $fields)
    {
        foreach($fields as $attribute) {
            echo ucfirst($attribute) . ' [' . $product->getData($attribute) . "] \t";
        }
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     */
    protected function _printAssignedWebsites(\Magento\Catalog\Model\Product $product)
    {
        if (!$this->_printWebsites) {
            return;
        }
        $collection = $this->_websitesFactory->create();
        $collection->addIdFilter($product->getWebsiteIds());

        $websiteNames = array();
        /** @var \Magento\Store\Model\Website $website */
        foreach ($collection as $website) {
            $websiteNames[] = $website->getName();
        }


        echo 'Websites ';
        echo '[' . implode(',', $websiteNames) . ']';
        echo "\t";
    }

}