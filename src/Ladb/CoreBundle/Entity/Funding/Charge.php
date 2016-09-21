<?php

namespace Ladb\CoreBundle\Entity\Funding;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table("tbl_funding_charge")
 * @ORM\Entity(repositoryClass="Ladb\CoreBundle\Repository\Funding\DonationRepository")
 */
class Charge {

	const CLASS_NAME = 'LadbCoreBundle:Funding\Charge';

	const TYPE_UNKNOW = 0;
	const TYPE_HOSTING = 1;

	/**
	 * @ORM\Column(name="id", type="integer")
	 * @ORM\Id
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	private $id;

	/**
	 * @ORM\Column(name="duty_free_amount", type="integer")
	 */
	private $dutyFreeAmount = 0;

	/**
	 * @ORM\Column(type="integer")
	 */
	private $amount = 0;

	/**
	 * @ORM\Column(type="smallint")
	 */
	private $type = Charge::TYPE_UNKNOW;

	/**
	 * @ORM\ManyToOne(targetEntity="Ladb\CoreBundle\Entity\Funding\Funding", inversedBy="charges")
	 * @ORM\JoinColumn(nullable=true)
	 */
	private $funding = null;

	/////

	public function getId() {
		return $this->id;
	}

	// DutyFreeAmount /////

	public function setDutyFreeAmount($dutyFreeAmount) {
		$this->dutyFreeAmount = $dutyFreeAmount;
		return $this;
	}

	public function getDutyFreeAmount() {
		return $this->dutyFreeAmount;
	}

	public function getDutyFreeAmountEur() {
		return $this->getDutyFreeAmount() / 100;
	}

	// Amount /////

	public function setAmount($amount) {
		$this->amount = $amount;
		return $this;
	}

	public function getAmount() {
		return $this->amount;
	}

	public function getAmountEur() {
		return $this->getAmount() / 100;
	}

	// Type /////

	public function setType($type) {
		$this->type = $type;
		return $this;
	}

	public function getType() {
		return $this->type;
	}

	// Funding /////

	public function setFunding($funding) {
		$this->funding = $funding;
		return $this;
	}

	public function getFunding() {
		return $this->funding;
	}

}