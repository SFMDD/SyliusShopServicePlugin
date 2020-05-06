<?php

namespace FMDD\SyliusShopServicePlugin\Service;

use Doctrine\ORM\NonUniqueResultException;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\OrderRepository;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductTaxonRepository;
use Sylius\Bundle\TaxonomyBundle\Doctrine\ORM\TaxonRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Sylius\Component\Taxation\Calculator\CalculatorInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ShopService
{
    /**
     * @var ContainerInterface
     */
    protected $container;
    /**
     * @var TaxRateResolverInterface
     */
    private $taxRateResolver;

    /**
     * @var CalculatorInterface
     */
    private $calculator;

    /**
     * @var ProductVariantResolverInterface
     */
    private $variantResolver;

    private $repoTaxon;
    private $repoProductTaxon;
    private $repoProduct;
    private $repoOrder;

    public function __construct(
        TaxonRepository $repoTaxon,
        ProductTaxonRepository $productTaxonRepository,
        ProductRepository $repoProduct,
        OrderRepository $repoOrder,
        TaxRateResolverInterface $taxRateResolver,
        CalculatorInterface $calculator,
        ProductVariantResolverInterface $variantResolver
    )
    {
        $this->repoTaxon = $repoTaxon;
        $this->repoProductTaxon = $productTaxonRepository;
        $this->repoProduct = $repoProduct;
        $this->repoOrder = $repoOrder;
        $this->taxRateResolver = $taxRateResolver; // sylius.tax_rate_resolver
        $this->calculator = $calculator; // sylius.tax_calculator
        $this->variantResolver = $variantResolver; // sylius.product_variant_resolver.default
    }

    public function getTaxonByCode($code)
    {
        return $this->repoTaxon->findOneBy(array('code' => $code));
    }

    /**
     * @param TaxonInterface $taxonomy
     * @return bool|int|mixed
     * @throws \Doctrine\ORM\NoResultException
     */
    public function checkIfTaxonomyContentProductEnable($taxonomy)
    {
        try {
            $products = $this->repoProductTaxon->createQueryBuilder('pt')
                ->select('count(pt.id)')
                ->innerJoin('pt.product', 'product', 'WITH', 'product.enabled = 1')
                ->andWhere('pt.taxon = ?0')
                ->setParameter(0, $taxonomy->getId())
                ->getQuery()->getSingleScalarResult();
        } catch (NonUniqueResultException $e) {
            $products = 0;
        }

        if ($products > 0)
            return $products;
        return false;
    }

    public function getProductInTaxonAndShowHorizontaleListAction($taxon)
    {
        $products = array();
        if (!is_null($taxon)) {
            $productsTaxons = $this->repoProductTaxon->findBy(array('taxon' => $taxon), null, 15);
            /** @var ProductTaxon $pTaxon */
            foreach ($productsTaxons as $pTaxon) {
                if ($pTaxon->getProduct() !== null && $pTaxon->getProduct()->isEnabled())
                    array_push($products, $pTaxon->getProduct());
            }
        }
        return $products;
    }

    public function getRandomProducts()
    {
        $products = $this->repoProduct->findAll();
        $list = array();
        $count = 0;
        if (!empty($products)) {
            while (sizeof($list) < 15 && $count < 50) {
                shuffle($products);
                /** @var ProductInterface $p */
                $p = $products[0];
                if ($p !== null) {
                    if ($p->isEnabled())
                        array_push($list, $products[0]);
                    unset($products[0]);
                    if (empty($products)) break;
                }
                $count++;
            }
        }
        return $list;
    }

    /**
     * @param string $number
     * @return object|Order
     */
    public function getOrder($number)
    {
        return $this->repoOrder->findOneBy(array('number' => $number));
    }

    /**
     * @param $code
     * @return object|Taxon
     */
    public function getTaxon($code)
    {
        return $this->repoTaxon->findOneBy(array('code' => $code));
    }

    /**
     * @param ProductInterface $product
     *
     * @param ChannelInterface $channel
     * @return array
     */
    public function getPricing(ProductInterface $product, ChannelInterface $channel): array
    {
        /** @var ProductVariantInterface $variant */
        $variant = $this->variantResolver->getVariant($product);
        return $this->getPricingVariant($variant, $channel);
    }

    /**
     * @param ProductVariantInterface $variant
     * @param ChannelInterface $channel
     * @param bool $original
     * @return array
     */
    public function getPricingVariant(ProductVariantInterface $variant, ChannelInterface $channel, $original = false): array
    {
        $tmp = [];
        $price = $this->getPricingArray($variant, $channel);
        $tmp['price'] = ['tax' => $price[0], 'noTax' => $price[1]];
        if($original){
            $originalPricing = $this->getPricingArray($variant, $channel);
            $tmp['originalPrice'] = ['tax' => $originalPricing[0], 'noTax' => $originalPricing[1]];
        }
        return $tmp;
    }

    /**
     * @param ProductVariantInterface $variant
     * @param ChannelInterface $channel
     * @param bool $originalPrice
     * @return array
     */
    private function getPricingArray(ProductVariantInterface $variant, ChannelInterface $channel, $originalPrice = false){

        if(!$originalPrice) $price = $variant->getChannelPricingForChannel($channel)->getPrice();
        else $price = $variant->getChannelPricingForChannel($channel)->getOriginalPrice();
        $taxRate = $this->taxRateResolver->resolve($variant);
        $totalTaxAmount = $this->calculator->calculate($price, $taxRate);

        if ($taxRate->isIncludedInPrice()) {
            $priceWithTax = $price;
            $priceWithoutTax = $price - $totalTaxAmount;
        } else {
            $priceWithTax = $price + $totalTaxAmount;
            $priceWithoutTax = $price;
        }
        return [$priceWithTax, $priceWithoutTax];
    }
}
