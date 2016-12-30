<?php

namespace Youyiche\UserBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Youyiche\UserBundle\Entity\User;
use Doctrine\ORM\Query;
use Youyiche\AuctionBundle\Entity\AuctionExamtask;

class JpushMerchantAuctionNoPvCommand extends ContainerAwareCommand {

    /**
     * {@inheritDoc}
     */
    public function configure()
    {
        parent::configure();
        $this->setName('youyiche:jpush_merchantauctionnopv_command');
        $this->setDescription('JpushMerchantAuctionNoPvCommand');
    }

    /**
     * {@inheritDoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $totalAuctions = $this->getTotalAuctions();
        $totalGroups = $this->getTotalGroups($totalAuctions);
        $totalUsers = $this->getTotalUsers($totalGroups);
        $noPvBizs = $this->getNoPvBiz($totalUsers);
        $common_util = $this->getContainer()->get("common_util");

        $container = $this->getContainer();
        $em = $container->get('doctrine')->getManager();
        $uRepo = $em->getRepository('YouyicheUserBundle:User');
        $msgjpush = [
            'custom_tag'=> 'send_nopv_bizs',
            'type' => 'send_nopv_bizs',
            'extras' => [],
            'alert' => ''
        ];
        foreach ($noPvBizs as $key => $noPvBiz) {
            $user = $uRepo->find($noPvBiz);
            $count = $this->getNowAuction($user);
            if($count == 0){
                continue;
            }
            $msgjpush['alert'] = '今天为您找到'.$count.'条新的车源信息，请查看详情。';
            $msgjpush['user_ids'] = [$noPvBiz];
            $common_util->rabbitmqSend("jpush_sender", $msgjpush);
            print_r($msgjpush);
        }
    }

    //1 获取当前有效的定向拍卖场
    //获取每个拍卖场 可以参与拍卖的 车商组--车商
    //获取 拍卖场 下面的 在拍车辆数
    //2 获取 某个定向拍卖场 所有车辆 有浏览记录的车商
    //3 获取每个拍卖场 可以参与拍卖的 车商组--车商 条件 不在 已经有浏览记录的车商里面  获得 没有浏览的车商
    //4 车辆数 没有浏览的车商

    //++++++++++++++++++++++++
    //获取 当前的 定向拍卖场
    //获取 所有 拍卖场 所有 车商组
    //查询 当天 所有 有浏览记录的 车商
    //通过车商组获取 对应的所有 车商 再排除有浏览记录的车商， 获取没有浏览记录的车商

    // 针对 每个车商 对应的车商组-》定向拍卖场-》可以参拍的车辆总数》jpush

    public function getTotalAuctions()
    {
        $container = $this->getContainer();
        $em = $container->get('doctrine')->getManager();
        $qb = $em->createQueryBuilder();
        $qb->select('au.id');
        $re = $qb->from('YouyicheAuctionBundle:Auction', 'au')
                     ->where('au.enable = 1')
                     ->getQuery()
                     ->getArrayResult();
        $totalAuction = $container->get('generic_bp')->getSimpleArray($re);
        return $totalAuction;
    }

    //所有定向拍卖场对应的所有 车商组
    public function getTotalGroups($auctions)
    {
        $container = $this->getContainer();
        $em = $container->get('doctrine')->getManager();
        $connection = $em->getConnection();
        $str = implode(',', $auctions);
        $str = "(".$str.")";
        $sql = "select group_id from merchants_auction as ma where ma.auction_id in $str";
        $prepare = $connection->prepare($sql);
        $prepare->execute();
        $results = $prepare->fetchAll();
        $result = $container->get('generic_bp')->getSimpleArray($results);
        $result = array_unique($result);
        return $result;
    }

    //所有定向拍卖场对应的所有 车商组 对应的用户
    public function getTotalUsers($groups)
    {
        $container = $this->getContainer();
        $em = $container->get('doctrine')->getManager();
        $connection = $em->getConnection();
        $str = implode(',', $groups);
        $str = "(".$str.")";
        $sql = "select user_id from merchants_group as ma where ma.group_id in $str";
        $prepare = $connection->prepare($sql);
        $prepare->execute();
        $results = $prepare->fetchAll();
        $result = $container->get('generic_bp')->getSimpleArray($results);
        $result = array_unique($result);
        return $result;
    }

    //获取有浏览记录的商户数(H5/Android/iOS任一平台只要浏览过一台车就算浏览过)
    //统计的是有浏览的商户数
    public function totalPageviewBiz()
    {
        $regDateStart = new \DateTime();
        $regDateEnd = new \DateTime();
        $regDateStart->setTime(0,0,0);
        $regDateEnd->setTime(23,59,59);
        $container = $this->getContainer();
        $em = $container->get('doctrine')->getManager();
        $qb = $em->createQueryBuilder();
        $qb->select('user.id AS userid');
        $re = $qb->from('YouyicheAuctionBundle:AEPageView', 'pv')
                     ->leftJoin("pv.user", "user")
                     ->where('pv.time >= :timestart')
                     ->andwhere('pv.time <= :timeend')
                     ->setParameter('timestart', $regDateStart)
                     ->setParameter('timeend', $regDateEnd)
                     ->groupBy('user.id')
                     ->getQuery()
                     ->getArrayResult();
    $totalbiz = $container->get('generic_bp')->getSimpleArray($re);
    return $totalbiz;
    }

    private function getNoPvBiz($totalUsers)
    {
        $container = $this->getContainer();
        $em = $container->get('doctrine')->getManager();
        $havePageViewBiz = $this->totalPageviewBiz();
        $noPvBizs = array_filter($totalUsers,function($totalUser)use($havePageViewBiz){
            return !in_array($totalUser, $havePageViewBiz);
        });
        
        return $noPvBizs;
    }

    //根据用户获得 对应的动态拍卖场[不包含之前 的 现场拍，寄售，一口价]
    public function getUserAuctions($user)
    {
        $getMerchants = $user->getMerchants();
        $auctionsAll = [];
        foreach($getMerchants as $getMerchant){
            $auctions = $getMerchant->getAuctions();
            foreach($auctions as $auction){
                if($auction->getEnable()){
                    $auctionsAll[] = $auction;
                }
            }
        }
        if(!empty($auctionsAll)){
            usort($auctionsAll, function($auctionA, $auctionB){
                return $auctionA->getSort() - $auctionB->getSort();
            });
        }
        //定向拍卖场去重
        $uniqueAuction = [];
        foreach($auctionsAll as $auction){
            $channel = $auction->getId();
            $uniqueAuction[$channel] = $auction;
        }
        return array_values($uniqueAuction);
    }


    //当天上拍且未下拍的车辆总数
    public function getNowAuction($user)
    {
        $userAuction = $this->getUserAuctions($user);
        //默认日期去掉"2016-03-03 11:14:15.638276"
        $regDateStart = new \DateTime();
        $regDateEnd = new \DateTime();
        $regDateStart->setTime(0,0,0);
        $regDateEnd->setTime(23,59,59);
        $container = $this->getContainer();
        $em = $container->get('doctrine')->getManager();
        $qb = $em->createQueryBuilder();
        $qb->select('COUNT(ae.id) AS aeid');
        $re = $qb->from('YouyicheAuctionBundle:AuctionExamtask', 'ae')
                     ->leftJoin("ae.auction", "auction")
                     ->where('ae.createdAt >= :timestart')
                     ->andwhere('ae.createdAt <= :timeend')
                     ->andwhere('ae.status in (:aestatus)')
                     ->andwhere('auction in (:auctions)')
                     ->setParameter('timestart', $regDateStart)
                     ->setParameter('timeend', $regDateEnd)
                     ->setParameter('aestatus', [AuctionExamtask::STATUS_BIDDING, AuctionExamtask::STATUS_BIDDING_2])
                     ->setParameter('auctions', $userAuction)
                     ->getQuery()
                     ->getSingleResult();
        return $re['aeid'];
    }
}
