<?php

namespace App\Command;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Layout;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\DataObject\Service;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates Product, Category, and Supplier Pimcore classes with a
 * "ProductTypeAttributes" classification store covering three product lines:
 *   - DME (Durable Medical Equipment)
 *   - Fragrances
 *   - Cosmetics
 *
 * Run: bin/console app:setup-product-classes
 * After: bin/console pimcore:deployment:classes-rebuild
 */
#[AsCommand(
    name: 'app:setup-product-classes',
    description: 'Creates Product, Category, and Supplier Pimcore classes with classification store',
)]
class SetupProductClassesCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-create classes even if they already exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Pimcore Product Class Setup');

        try {
            $io->section('1/5  Classification Store');
            $storeId = $this->setupClassificationStore($io);

            $io->section('2/5  Category class');
            $this->createCategoryClass($io);

            $io->section('3/5  Supplier class');
            $this->createSupplierClass($io);

            $io->section('4/5  Product class');
            $this->createProductClass($io, $storeId);

            $io->section('5/5  Object folder structure');
            $this->setupFolders($io);

            $io->success([
                'All classes created successfully.',
                'Next step: bin/console pimcore:deployment:classes-rebuild',
            ]);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    // =========================================================================
    // Classification Store
    // =========================================================================

    private function setupClassificationStore(SymfonyStyle $io): int
    {
        $store = $this->getOrCreateStore(
            'ProductTypeAttributes',
            'Product-type-specific attributes (DME, Fragrances, Cosmetics)'
        );
        $storeId = $store->getId();
        $io->text("Store \"ProductTypeAttributes\" → ID $storeId");

        $this->buildDMEGroup($storeId, $io);
        $this->buildFragrancesGroup($storeId, $io);
        $this->buildCosmeticsGroup($storeId, $io);

        return $storeId;
    }

    private function buildDMEGroup(int $storeId, SymfonyStyle $io): void
    {
        $group = $this->getOrCreateGroup('DME', 'Durable Medical Equipment specific attributes', $storeId);
        $gid  = $group->getId();
        $io->text("  DME group → ID $gid");

        $fields = [
            ['hcpcCode',         'HCPC Code',                $this->input()],
            ['fdaClass',         'FDA Class',                $this->select(['Class I', 'Class II', 'Class III'])],
            ['sterile',          'Sterility',                $this->select(['Non-Sterile', 'Sterile', 'Individually Sterile'])],
            ['disposable',       'Disposable / Reusable',    $this->select(['Reusable', 'Disposable', 'Single-Use'])],
            ['latexFree',        'Latex Free',               $this->checkbox()],
            ['rxRequired',       'Rx Required',              $this->checkbox()],
            ['medicareEligible', 'Medicare Eligible',        $this->checkbox()],
            ['medicaidEligible', 'Medicaid Eligible',        $this->checkbox()],
            ['weightCapacity',   'Weight Capacity (lbs)',    $this->numeric()],
            ['warrantyPeriod',   'Warranty Period',          $this->input()],
            ['plusFreight',      'Plus Freight',             $this->checkbox()],
        ];

        foreach ($fields as $i => [$name, $title, $def]) {
            $this->addKeyToGroup($name, $title, $def, $storeId, $gid, $i + 1);
        }
    }

    private function buildFragrancesGroup(int $storeId, SymfonyStyle $io): void
    {
        $group = $this->getOrCreateGroup('Fragrances', 'Fragrance-specific attributes', $storeId);
        $gid  = $group->getId();
        $io->text("  Fragrances group → ID $gid");

        $scentFamilies = ['Floral', 'Oriental', 'Woody', 'Fresh', 'Citrus', 'Fougère', 'Chypre', 'Gourmand', 'Aquatic', 'Musk'];
        $fields = [
            ['concentration', 'Concentration',  $this->select(['Parfum', 'Eau de Parfum', 'Eau de Toilette', 'Eau de Cologne', 'Body Spray'])],
            ['gender',        'Gender',          $this->select(['Men', 'Women', 'Unisex'])],
            ['scentFamily',   'Scent Family',    $this->multiselect($scentFamilies)],
            ['topNotes',      'Top Notes',       $this->input()],
            ['middleNotes',   'Middle Notes',    $this->input()],
            ['baseNotes',     'Base Notes',      $this->input()],
            ['longevity',     'Longevity',       $this->select(['Poor', 'Weak', 'Moderate', 'Long Lasting', 'Eternal'])],
            ['sillage',       'Sillage',         $this->select(['Intimate', 'Moderate', 'Strong', 'Enormous'])],
            ['season',        'Season',          $this->multiselect(['Spring', 'Summer', 'Fall', 'Winter', 'All Season'])],
            ['occasion',      'Occasion',        $this->multiselect(['Casual', 'Office', 'Evening', 'Sport', 'Wedding'])],
        ];

        foreach ($fields as $i => [$name, $title, $def]) {
            $this->addKeyToGroup($name, $title, $def, $storeId, $gid, $i + 1);
        }
    }

    private function buildCosmeticsGroup(int $storeId, SymfonyStyle $io): void
    {
        $group = $this->getOrCreateGroup('Cosmetics', 'Cosmetics-specific attributes', $storeId);
        $gid  = $group->getId();
        $io->text("  Cosmetics group → ID $gid");

        $fields = [
            ['skinType',             'Skin Type',             $this->multiselect(['Normal', 'Dry', 'Oily', 'Combination', 'Sensitive', 'All'])],
            ['formulaType',          'Formula Type',          $this->select(['Cream', 'Lotion', 'Serum', 'Gel', 'Oil', 'Powder', 'Spray', 'Stick', 'Balm', 'Foam'])],
            ['shade',                'Shade / Color',         $this->input()],
            ['spf',                  'SPF',                   $this->numeric()],
            ['crueltyFree',          'Cruelty Free',          $this->checkbox()],
            ['vegan',                'Vegan',                 $this->checkbox()],
            ['organicNatural',       'Organic / Natural',     $this->checkbox()],
            ['dermatologistTested',  'Dermatologist Tested',  $this->checkbox()],
            ['ingredients',          'Ingredients',           $this->textarea()],
            ['usageInstructions',    'Usage Instructions',    $this->textarea()],
        ];

        foreach ($fields as $i => [$name, $title, $def]) {
            $this->addKeyToGroup($name, $title, $def, $storeId, $gid, $i + 1);
        }
    }

    // =========================================================================
    // Category Class
    // =========================================================================

    private function createCategoryClass(SymfonyStyle $io): void
    {
        [$class, $isNew] = $this->getOrCreateClass('Category');

        $root = $this->root();
        $tabs = $this->tabpanel();
        $root->addChild($tabs);

        // General
        $general = $this->tab('General');
        $general->addChild($this->f(new Data\Input(),    'name',          'Name',           true));
        $general->addChild($this->f(new Data\Textarea(), 'description',   'Description'));
        $general->addChild($this->f(new Data\Image(),    'image',         'Category Image'));
        $general->addChild($this->f(
            $this->select(['Major', 'Minor']),
            'categoryLevel', 'Category Level'
        ));
        $tabs->addChild($general);

        // Relations
        $relations = $this->tab('Relations');
        $parentRel = new Data\ManyToOneRelation();
        $parentRel->setName('parentCategory');
        $parentRel->setTitle('Parent Category');
        $parentRel->setClasses([['classes' => 'Category']]);
        $relations->addChild($parentRel);
        $tabs->addChild($relations);

        $class->setLayoutDefinitions($root);
        $class->setAllowInherit(true);
        $class->save();

        $io->text(($isNew ? 'Created' : 'Updated') . ' Category class');
    }

    // =========================================================================
    // Supplier Class
    // =========================================================================

    private function createSupplierClass(SymfonyStyle $io): void
    {
        [$class, $isNew] = $this->getOrCreateClass('Supplier');

        $root = $this->root();
        $tabs = $this->tabpanel();
        $root->addChild($tabs);

        // General
        $general = $this->tab('General');
        $general->addChild($this->f(new Data\Input(),    'name',            'Supplier Name',          true));
        $general->addChild($this->f(new Data\Input(),    'code',            'Supplier Code',          true));
        $general->addChild($this->f(new Data\Checkbox(), 'active',          'Active'));
        $general->addChild($this->f(new Data\Checkbox(), 'dropShipCapable', 'Drop Ship Capable'));
        $general->addChild($this->f(new Data\Checkbox(), 'wholesaleCapable','Wholesale Capable'));
        $general->addChild($this->f(new Data\Numeric(),  'defaultLeadTime', 'Default Lead Time (days)'));
        $tabs->addChild($general);

        // Contact
        $contact = $this->tab('Contact');
        $contact->addChild($this->f(new Data\Input(), 'contactName', 'Contact Name'));
        $contact->addChild($this->f(new Data\Email(), 'email',       'Email'));
        $contact->addChild($this->f(new Data\Input(), 'phone',       'Phone'));
        $contact->addChild($this->f(new Data\Input(), 'website',     'Website'));
        $tabs->addChild($contact);

        // Address
        $address = $this->tab('Address');
        $address->addChild($this->f(new Data\Input(),   'street',  'Street Address'));
        $address->addChild($this->f(new Data\Input(),   'city',    'City'));
        $address->addChild($this->f(new Data\Input(),   'state',   'State / Province'));
        $address->addChild($this->f(new Data\Input(),   'zipCode', 'ZIP / Postal Code'));
        $address->addChild($this->f(new Data\Country(), 'country', 'Country'));
        $tabs->addChild($address);

        $class->setLayoutDefinitions($root);
        $class->save();

        $io->text(($isNew ? 'Created' : 'Updated') . ' Supplier class');
    }

    // =========================================================================
    // Product Class
    // =========================================================================

    private function createProductClass(SymfonyStyle $io, int $storeId): void
    {
        [$class, $isNew] = $this->getOrCreateClass('Product');

        $root = $this->root();
        $tabs = $this->tabpanel();
        $root->addChild($tabs);

        // ── General ──────────────────────────────────────────────────────────
        $general = $this->tab('General');
        $general->addChild($this->f(new Data\Input(),    'name',           'Product Name',    true));
        $general->addChild($this->f(new Data\Input(),    'sku',            'Item Number / SKU', true));
        $general->addChild($this->f(new Data\Checkbox(), 'active',         'Active'));
        $general->addChild($this->f(new Data\Input(),    'productGroup',   'Product Group'));
        $general->addChild($this->f(new Data\Country(),  'countryOfOrigin','Country of Origin'));
        $tabs->addChild($general);

        // ── Descriptions ─────────────────────────────────────────────────────
        $desc = $this->tab('Descriptions');
        $desc->addChild($this->f(new Data\Textarea(), 'shortDescription',    'Description'));
        $desc->addChild($this->f(new Data\Wysiwyg(),  'extendedDescription', 'Extended Description'));
        $tabs->addChild($desc);

        // ── Pricing ───────────────────────────────────────────────────────────
        $pricing = $this->tab('Pricing');
        $pricing->addChild($this->f($this->decimalNumeric(), 'standardPrice', 'Standard Price'));
        $pricing->addChild($this->f($this->decimalNumeric(), 'listPrice',     'List Price'));
        $pricing->addChild($this->f($this->decimalNumeric(), 'mapPrice',      'MAP Price'));
        $tabs->addChild($pricing);

        // ── Inventory & Logistics ─────────────────────────────────────────────
        $inventory = $this->tab('Inventory');
        $inventory->addChild($this->f(new Data\Numeric(), 'availableQuantity', 'Available Quantity'));
        $inventory->addChild($this->f(
            $this->select(['N' => 'No Drop Ship', 'Y' => 'Yes Drop Ship', 'O' => 'On Order']),
            'dropShipStatus', 'Drop Ship Status'
        ));
        $inventory->addChild($this->f(new Data\Checkbox(), 'plusFreight', 'Plus Freight'));
        $inventory->addChild($this->f(new Data\Numeric(),  'avgLeadTime', 'Average Lead Time (days)'));
        $tabs->addChild($inventory);

        // ── Dimensions ────────────────────────────────────────────────────────
        $dims = $this->tab('Dimensions');

        $prodDims = new Layout\Panel();
        $prodDims->setName('productDimensions');
        $prodDims->setTitle('Product Dimensions (in)');
        $prodDims->addChild($this->f(new Data\Numeric(), 'productLength', 'Length'));
        $prodDims->addChild($this->f(new Data\Numeric(), 'productWidth',  'Width'));
        $prodDims->addChild($this->f(new Data\Numeric(), 'productHeight', 'Height'));
        $dims->addChild($prodDims);

        $shipDims = new Layout\Panel();
        $shipDims->setName('shippingDimensions');
        $shipDims->setTitle('Shipping Dimensions (in)');
        $shipDims->addChild($this->f(new Data\Numeric(), 'shippingLength', 'Shipping Length'));
        $shipDims->addChild($this->f(new Data\Numeric(), 'shippingWidth',  'Shipping Width'));
        $shipDims->addChild($this->f(new Data\Numeric(), 'shippingHeight', 'Shipping Height'));
        $dims->addChild($shipDims);

        $dims->addChild($this->f(new Data\Numeric(), 'itemWeight',    'Item Weight (lbs)'));
        $dims->addChild($this->f(new Data\Numeric(), 'itemNetWeight', 'Item Net Weight (lbs)'));
        $tabs->addChild($dims);

        // ── Identifiers ───────────────────────────────────────────────────────
        $ids = $this->tab('Identifiers');
        $ids->addChild($this->f(new Data\Input(), 'upcCode', 'UPC Code'));
        $ids->addChild($this->f(new Data\Date(),  'dateEstablished', 'Date Established'));
        $ids->addChild($this->f(new Data\Date(),  'poDate',          'PO Date'));
        $tabs->addChild($ids);

        // ── Media ─────────────────────────────────────────────────────────────
        $media = $this->tab('Media');
        $media->addChild($this->f(new Data\Image(),        'mainImage', 'Main Image'));
        $media->addChild($this->f(new Data\ImageGallery(), 'images',    'Image Gallery'));
        $tabs->addChild($media);

        // ── Relations ─────────────────────────────────────────────────────────
        $relations = $this->tab('Relations');

        $categories = new Data\ManyToManyObjectRelation();
        $categories->setName('categories');
        $categories->setTitle('Categories');
        $categories->setClasses([['classes' => 'Category']]);
        $relations->addChild($categories);

        $supplier = new Data\ManyToOneRelation();
        $supplier->setName('supplier');
        $supplier->setTitle('Primary Supplier');
        $supplier->setClasses([['classes' => 'Supplier']]);
        $relations->addChild($supplier);

        $additionalSuppliers = new Data\ManyToManyObjectRelation();
        $additionalSuppliers->setName('additionalSuppliers');
        $additionalSuppliers->setTitle('Additional Suppliers');
        $additionalSuppliers->setClasses([['classes' => 'Supplier']]);
        $relations->addChild($additionalSuppliers);

        $tabs->addChild($relations);

        // ── Product Type Attributes (Classification Store) ────────────────────
        $typeTab = $this->tab('Type Attributes');
        $csField = new Data\Classificationstore();
        $csField->setName('typeAttributes');
        $csField->setTitle('Product Type Attributes');
        $csField->setStoreId($storeId);
        $typeTab->addChild($csField);
        $tabs->addChild($typeTab);

        $class->setLayoutDefinitions($root);
        $class->setAllowInherit(false);
        $class->save();

        $io->text(($isNew ? 'Created' : 'Updated') . ' Product class');
    }

    // =========================================================================
    // Object Folder Structure
    // =========================================================================

    private function setupFolders(SymfonyStyle $io): void
    {
        $paths = [
            '/Products',
            '/Products/DME',
            '/Products/Fragrances',
            '/Products/Cosmetics',
            '/Categories',
            '/Suppliers',
        ];

        foreach ($paths as $path) {
            Service::createFolderByPath($path);
            $io->text("  $path");
        }
    }

    // =========================================================================
    // ClassificationStore helpers
    // =========================================================================

    private function getOrCreateStore(string $name, string $description): Classificationstore\StoreConfig
    {
        $listing = new Classificationstore\StoreConfig\Listing();
        $listing->setCondition('name = ?', [$name]);
        $stores = $listing->load();

        if (!empty($stores)) {
            return $stores[0];
        }

        $store = new Classificationstore\StoreConfig();
        $store->setName($name);
        $store->setDescription($description);
        $store->save();

        return $store;
    }

    private function getOrCreateGroup(string $name, string $description, int $storeId): Classificationstore\GroupConfig
    {
        $listing = new Classificationstore\GroupConfig\Listing();
        $listing->setCondition('name = ? AND storeId = ?', [$name, $storeId]);
        $groups = $listing->load();

        if (!empty($groups)) {
            return $groups[0];
        }

        $group = new Classificationstore\GroupConfig();
        $group->setName($name);
        $group->setDescription($description);
        $group->setStoreId($storeId);
        $group->save();

        return $group;
    }

    private function addKeyToGroup(
        string $name,
        string $title,
        Data $definition,
        int $storeId,
        int $groupId,
        int $sorter = 0
    ): void {
        // Get or create the key
        $listing = new Classificationstore\KeyConfig\Listing();
        $listing->setCondition('name = ? AND storeId = ?', [$name, $storeId]);
        $keys = $listing->load();

        $key = !empty($keys) ? $keys[0] : new Classificationstore\KeyConfig();
        $definition->setName($name);
        $key->setName($name);
        $key->setTitle($title);
        $key->setType($definition->getFieldtype());
        $key->setStoreId($storeId);
        $key->setDefinition(json_encode($definition));
        $key->save();

        // Assign to group — table is created lazily by Pimcore on first save;
        // catch duplicate-key on re-runs rather than pre-checking the table.
        try {
            $relation = new Classificationstore\KeyGroupRelation();
            $relation->setKeyId($key->getId());
            $relation->setGroupId($groupId);
            $relation->setSorter($sorter);
            $relation->save();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Relation already exists — nothing to do.
        }
    }

    // =========================================================================
    // ClassDefinition helpers
    // =========================================================================

    /** @return array{ClassDefinition, bool} [$class, $isNew] */
    private function getOrCreateClass(string $name): array
    {
        $class = ClassDefinition::getByName($name);
        $isNew = !$class;

        if ($isNew) {
            $class = new ClassDefinition();
            $class->setName($name);
        }

        return [$class, $isNew];
    }

    // =========================================================================
    // Layout helpers
    // =========================================================================

    private function root(): Layout\Panel
    {
        $p = new Layout\Panel();
        $p->setName('pimcore_root');
        return $p;
    }

    private function tabpanel(): Layout\Tabpanel
    {
        $t = new Layout\Tabpanel();
        $t->setName('tabpanel');
        return $t;
    }

    private function tab(string $title): Layout\Panel
    {
        $p = new Layout\Panel();
        $p->setName(lcfirst(str_replace(' ', '', $title)));
        $p->setTitle($title);
        return $p;
    }

    /** Configure name/title/mandatory on any data field and return it. */
    private function f(Data $field, string $name, string $title, bool $mandatory = false): Data
    {
        $field->setName($name);
        $field->setTitle($title);
        if ($mandatory) {
            $field->setMandatory(true);
        }
        return $field;
    }

    // =========================================================================
    // Data field factory helpers
    // =========================================================================

    private function input(): Data\Input
    {
        return new Data\Input();
    }

    private function numeric(): Data\Numeric
    {
        return new Data\Numeric();
    }

    private function decimalNumeric(): Data\Numeric
    {
        $n = new Data\Numeric();
        $n->setDecimalSize(2);
        return $n;
    }

    private function checkbox(): Data\Checkbox
    {
        return new Data\Checkbox();
    }

    private function textarea(): Data\Textarea
    {
        return new Data\Textarea();
    }

    private function select(array $options): Data\Select
    {
        $items = [];
        foreach ($options as $key => $label) {
            // Support ['val'] and ['key' => 'label']
            if (is_int($key)) {
                $items[] = ['key' => $label, 'value' => $label];
            } else {
                $items[] = ['key' => $label, 'value' => $key];
            }
        }

        $field = new Data\Select();
        $field->setOptions($items);
        return $field;
    }

    private function multiselect(array $options): Data\Multiselect
    {
        $items = array_map(fn ($v) => ['key' => $v, 'value' => $v], $options);

        $field = new Data\Multiselect();
        $field->setOptions($items);
        return $field;
    }
}
