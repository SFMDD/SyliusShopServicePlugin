<?php

namespace FMDD\SyliusShopServicePlugin\Utils;

use Doctrine\ORM\NonUniqueResultException;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\OrderRepository;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductTaxonRepository;
use Sylius\Bundle\TaxonomyBundle\Doctrine\ORM\TaxonRepository;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Model\ProductInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ShopService
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    private $repoTaxon;
    private $repoProductTaxon;
    private $repoProduct;
    private $repoOrder;

    public function __construct(
        TaxonRepository $repoTaxon,
        ProductTaxonRepository $productTaxonRepository,
        ProductRepository $repoProduct,
        OrderRepository $repoOrder
    ){
        $this->repoTaxon = $repoTaxon;
        $this->repoProductTaxon = $productTaxonRepository;
        $this->repoProduct = $repoProduct;
        $this->repoOrder = $repoOrder;
    }

    public function getTaxonByCode($code) {
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
                    if(empty($products)) break;
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
    public function getOrder($number) {
        return $this->repoOrder->findOneBy(array('number' => $number));
    }

    /**
     * @param $code
     * @return object|Taxon
     */
    public function getTaxon($code){
        return $this->repoTaxon->findOneBy(array('code' => $code));
    }
}
