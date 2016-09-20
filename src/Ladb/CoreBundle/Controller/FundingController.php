<?php

namespace Ladb\CoreBundle\Controller;

use Ladb\CoreBundle\Utils\MailerUtils;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Ladb\CoreBundle\Entity\Funding\Donation;
use Ladb\CoreBundle\Entity\Funding\Funding;
use Ladb\CoreBundle\Manager\Funding\FundingManager;
use Ladb\CoreBundle\Utils\PaginatorUtils;

/**
 * @Route("/financement")
 */
class FundingController extends Controller {

	/**
	 * @Route(pattern="/", name="core_funding_dashboard")
	 * @Route(pattern="/{year}/{month}", requirements={"year" = "\d+", "month" = "\d+"}, name="core_funding_dashboard_year_month")
	 * @Template()
	 */
	public function dashboardAction(Request $request, $year = null, $month = null) {
		$om = $this->getDoctrine()->getManager();
		$fundingRepository = $om->getRepository(Funding::CLASS_NAME);

		// Retrieve parameters
		$amountEur = $request->get('amount_eur', 5);	// default amount = 5€
		$autoShow = $request->get('auto_show', false);

		if (is_null($year) || is_null($month)) {
			$fundingManager = $this->get(FundingManager::NAME);
			$funding = $fundingManager->getOrCreateCurrent();
		} else {
			$funding = $fundingRepository->findOneByYearAndMonth($year, $month);
		}
		if (is_null($funding)) {
			throw $this->createNotFoundException('Unable to find Funding entity (month='.$month.', year='.$year.').');
		}

		$prevDate = $funding->getId() == 1 ? null : new \DateTime($funding->getYear().'-'.$funding->getMonth().'-01 first day of previous month');
		$nextDate = $funding->getIsCurrent() ? null : new \DateTime($funding->getYear().'-'.$funding->getMonth().'-01 first day of next month');

		$prevPageUrl = $prevDate ? $this->get('router')->generate('core_funding_dashboard_year_month', array( 'month' => $prevDate->format('m'), 'year' => $prevDate->format('Y') )) : null;
		$nextPageUrl = $nextDate ? $this->get('router')->generate('core_funding_dashboard_year_month', array( 'month' => $nextDate->format('m'), 'year' => $nextDate->format('Y') )) : null;

		$parameters = array(
			'funding'     => $funding,
			'prevPageUrl' => $prevPageUrl,
			'nextPageUrl' => $nextPageUrl,
			'amountEur'   => $amountEur,
			'autoShow'    => $autoShow,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Funding:dashboard-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Route(pattern="/new", name="core_funding_new")
	 * @Template()
	 */
	public function newAction(Request $request) {

		// Retrieve parameters
		$amountEur = intval($request->get('amount_eur', 5));	// Default 5 euros

		if ($request->isXmlHttpRequest()) {

			$minAmountEur = $this->getParameter('funding_min_amount_eur');
			$maxAmountEur = $this->getParameter('funding_max_amount_eur');

			return array(
				'amountEur'    => $amountEur,
				'feeEur'       => $amountEur * 0.014 + 0.25,
				'validAmount'  => $amountEur >= $minAmountEur && $amountEur <= $maxAmountEur,
				'minAmountEur' => $minAmountEur,
				'maxAmountEur' => $maxAmountEur,
			);
		}

		return $this->redirect($this->generateUrl('core_funding_dashboard', array(
			'amount_eur' => $amountEur,
			'auto_show' => true,
		)));
	}

	/**
	 * @Route(pattern="/create", name="core_funding_create", defaults={"_format" = "json"})
	 * @Method("POST")
	 */
	public function createAction(Request $request) {
		$om = $this->getDoctrine()->getManager();

		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed (core_funding_create)');
		}
		if (!$this->getUser()->getEmailConfirmed()) {
			throw $this->createNotFoundException('User email is not confirmed.');
		}

		$minAmount = $this->getParameter('funding_min_amount_eur') * 100;
		$maxAmount = $this->getParameter('funding_max_amount_eur') * 100;

		// Retrieve parameters
		$amount = $request->get('amount');
		$token = $request->get('token');

		if (is_null($amount)) {
			throw $this->createNotFoundException('No amount.');
		}
		if ($amount < $minAmount || $amount > $maxAmount) {
			throw $this->createNotFoundException('Amount is out of range ($amount='.$amount.')');
		}
		if (is_null($token)) {
			throw $this->createNotFoundException('No token.');
		}

		// Setup Stripe API
		\Stripe\Stripe::setApiKey($this->getParameter('stripe_secret_key'));

		// Create a charge: this will charge the user's card
		try {

			// Create the Stripe charge
			$charge = \Stripe\Charge::create(array(
				'amount'        => $amount, // Amount in cents
				'currency'      => 'eur',
				'source'        => $token,
				'metadata'      => array('user_id' => $this->getUser()->getId()),
				"description"   => "Don au profit de L'Air du Bois",
			));

			// Retrieve the balance transaction
			$balanceTransaction = \Stripe\BalanceTransaction::retrieve($charge['balance_transaction']);

		} catch (\Stripe\Error\Card $e) {
			return new JsonResponse(array(
				'success' => false,
				'error_code' => $e->getStripeCode(),
				'message' => $this->get('translator')->trans('funding.message.pay_error.'.$e->getStripeCode()),
			));
		}

		// Create the Donation
		$donation = new Donation();
		$donation->setUser($this->getUser());
		$donation->setAmount($amount);
		$donation->setFee($balanceTransaction['fee']);
		$donation->setStripeChargeId($charge['id']);

		$om->persist($donation);

		// Update current Funding
		$fundingManager = $this->get(FundingManager::NAME);
		$funding = $fundingManager->getOrCreateCurrent();
		$funding->incrementDonationBalance($donation->getAmount() - $donation->getFee());

		// Increment user donation stats
		$this->getUser()->getMeta()->incrementDonationCount();
		$this->getUser()->getMeta()->incrementDonationBalance($donation->getAmount());
		$this->getUser()->getMeta()->incrementDonationFeeBalance($donation->getFee());

		$om->flush();	// Flush to be sure that donation id is generated

		// Generate donation hashid
		$hashids = new \Hashids\Hashids($this->getParameter('secret'), 5);
		$donation->setHashid($hashids->encode($donation->getId()));

		$om->flush();

		// Email confirmation (after persist to have a donation id)
		$mailerUtils = $this->get(MailerUtils::NAME);
		$mailerUtils->sendFundingPaymentReceiptEmailMessage($this->getUser(), $donation);


		return new JsonResponse(array(
			'success' => true,
			'content' => $this->get('templating')->render('LadbCoreBundle:Funding:create.html.twig', array( 'donation' => $donation )),
		));
	}

	/**
	 * @Route("/dons", name="core_funding_list")
	 * @Route("/dons/{filter}", requirements={"filter" = "\w+"}, name="core_funding_list_filter")
	 * @Route("/dons/{filter}/{page}", requirements={"filter" = "\w+", "page" = "\d+"}, name="core_funding_list_filter_page")
	 * @Template()
	 */
	public function listAction(Request $request, $filter = 'recent', $page = 0) {
		$om = $this->getDoctrine()->getManager();
		$donationRepository = $om->getRepository(Donation::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page, 20, 20);
		$limit = $paginatorUtils->computePaginatorLimit($page, 20, 20);
		$paginator = $donationRepository->findPaginedByUser($this->getUser(), $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_funding_list_filter_page', array( 'filter' => $filter ), $page, $paginator->count(), 20, 20);

		$parameters = array(
			'filter'        => $filter,
			'prevPageUrl'   => $pageUrls->prev,
			'nextPageUrl'   => $pageUrls->next,
			'donations'     => $paginator,
			'donationCount' => $paginator->count(),
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Funding:list-xhr.html.twig', $parameters);
		}
		return $parameters;

	}

}
