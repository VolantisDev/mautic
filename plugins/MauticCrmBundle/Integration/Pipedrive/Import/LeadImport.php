<?php

namespace MauticPlugin\MauticCrmBundle\Integration\Pipedrive\Import;

use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Deduplicate\Exception\SameContactException;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticCrmBundle\Entity\PipedriveDeletion;
use Symfony\Component\HttpFoundation\Response;

class LeadImport extends AbstractImport
{
    /**
     * @var LeadModel
     */
    private $leadModel;

    /**
     * @var CompanyModel
     */
    private $companyModel;

    /**
     * @var ContactMerger
     */
    private $contactMerger;

    /**
     * LeadImport constructor.
     */
    public function __construct(EntityManager $em, LeadModel $leadModel, CompanyModel $companyModel, ContactMerger $contactMerger)
    {
        parent::__construct($em);

        $this->leadModel     = $leadModel;
        $this->companyModel  = $companyModel;
        $this->contactMerger = $contactMerger;
    }

    /**
     * @return bool
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Exception
     */
    public function create(array $data = [])
    {
        $integrationEntity = $this->getLeadIntegrationEntity(['integrationEntityId' => $data['id']]);

        if ($integrationEntity) {
            throw new \Exception('Lead already have integration', Response::HTTP_CONFLICT);
        }
        $data         = $this->convertPipedriveData($data, $this->getIntegration()->getApiHelper()->getFields(self::PERSON_ENTITY_TYPE));
        $dataToUpdate = $this->getIntegration()->populateMauticLeadData($data);

        if (!$lead =  $this->leadModel->checkForDuplicateContact($dataToUpdate)) {
            $lead = new Lead();
        }
        // prevent listeners from exporting
        $lead->setEventData('pipedrive.webhook', 1);

        $this->leadModel->setFieldValues($lead, $dataToUpdate);

        if (isset($data['owner_id'])) {
            $this->addOwnerToLead($data['owner_id'], $lead);
        }

        $this->leadModel->saveEntity($lead);

        $integrationEntity = $this->getLeadIntegrationEntity(['integrationEntityId' => $data['id']]);
        if (!$integrationEntity) {
            $integrationEntity = $this->createIntegrationLeadEntity(new \DateTime(), $data['id'], $lead->getId());
        }

        $this->em->persist($integrationEntity);
        $this->em->flush();

        if (isset($data['org_id']) && $this->getIntegration()->isCompanySupportEnabled()) {
            $this->addLeadToCompany($data['org_id'], $lead);
            $this->em->flush();
        }

        return true;
    }

    /**
     * @return bool
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Exception
     */
    public function update(array $data = [])
    {
        $integrationEntity = $this->getLeadIntegrationEntity(['integrationEntityId' => $data['id']]);

        if (!$integrationEntity) {
            return $this->create($data);
        }

        /** @var Lead $lead * */
        $lead = $this->leadModel->getEntity($integrationEntity->getInternalEntityId());

        // prevent listeners from exporting
        $lead->setEventData('pipedrive.webhook', 1);

        $data         = $this->convertPipedriveData($data, $this->getIntegration()->getApiHelper()->getFields(self::PERSON_ENTITY_TYPE));
        $dataToUpdate = $this->getIntegration()->populateMauticLeadData($data);

        $lastSyncDate      = $integrationEntity->getLastSyncDate();
        $leadDateModified  = $lead->getDateModified();

        if ($lastSyncDate->format('Y-m-d H:i:s') >= $data['update_time']) {
            return false;
        } //Do not push lead if contact was modified in Mautic, and we don't wanna mofify it

        $lead->setDateModified(new \DateTime());
        $this->leadModel->setFieldValues($lead, $dataToUpdate, true);

        if (!isset($data['owner_id']) && $lead->getOwner()) {
            $lead->setOwner(null);
        } elseif (isset($data['owner_id'])) {
            $this->addOwnerToLead($data['owner_id'], $lead);
        }
        $this->leadModel->saveEntity($lead);

        $integrationEntity->setLastSyncDate(new \DateTime());
        $this->em->persist($integrationEntity);
        $this->em->flush();

        if (!$this->getIntegration()->isCompanySupportEnabled()) {
            return;
        }

        if (empty($data['org_id']) && $lead->getCompany()) {
            $this->removeLeadFromCompany($lead->getCompany(), $lead);
        } elseif (isset($data['org_id'])) {
            $this->addLeadToCompany($data['org_id'], $lead);
        }

        return true;
    }

    /**
     * @throws \Exception
     */
    public function delete(array $data = [])
    {
        $integrationEntity = $this->getLeadIntegrationEntity(['integrationEntityId' => $data['id']]);

        if (!$integrationEntity) {
            throw new \Exception('Lead doesn\'t have integration', Response::HTTP_NOT_FOUND);
        }

        $integrationSettings = $this->getIntegration()->getIntegrationSettings();
        $deleteViaCron       = ($integrationSettings->getIsPublished() && !empty($integrationSettings->getFeatureSettings()['cronDelete']));

        if ($deleteViaCron) {
            $deletion = new PipedriveDeletion();
            $deletion
                ->setObjectType('lead')
                ->setDeletedDate(new \DateTime())
                ->setIntegrationEntityId($integrationEntity->getId());

            $this->em->persist($deletion);
            $this->em->flush();
        } else {
            /** @var Lead $lead */
            $lead = $this->em->getRepository(Lead::class)->findOneById($integrationEntity->getInternalEntityId());

            if (!$lead) {
                throw new \Exception('Lead doesn\'t exists in Mautic', Response::HTTP_NOT_FOUND);
            }

            // prevent listeners from exporting
            $lead->setEventData('pipedrive.webhook', 1);

            $this->leadModel->deleteEntity($lead);

            if (!empty($lead->deletedId)) {
                $this->em->remove($integrationEntity);
            }
        }
    }

    /**
     * @return bool
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Exception
     */
    public function merge(array $data = [], array $otherData = [])
    {
        $otherIntegrationEntity = $this->getLeadIntegrationEntity(['integrationEntityId' => $otherData['id']]);

        if (!$otherIntegrationEntity) {
            // Only destination entity exists, so handle it as an update.
            return $this->update($data);
        }

        $integrationEntity = $this->getLeadIntegrationEntity(['integrationEntityId' => $data['id']]);

        if (!$integrationEntity) {
            // Destination entity doesn't yet exist, so create it first.
            $this->create($data);
            $integrationEntity = $this->getLeadIntegrationEntity(['integrationEntityId' => $data['id']]);
        }

        /** @var Lead $lead */
        $lead = $this->leadModel->getEntity($integrationEntity->getInternalEntityId());
        /** @var Lead $otherLead */
        $otherLead = $this->leadModel->getEntity($otherIntegrationEntity->getInternalEntityId());

        // prevent listeners from exporting
        $lead->setEventData('pipedrive.webhook', 1);

        try {
            $lead = $this->contactMerger->merge($lead, $otherLead);
            $this->em->remove($otherIntegrationEntity);
        } catch (SameContactException $exception) {
            // Ignore
        }

        $integrationEntity->setLastSyncDate(new \DateTime());
        $this->em->persist($integrationEntity);
        $this->em->flush();

        return true;
    }

    /**
     * @param $integrationOwnerId
     */
    private function addOwnerToLead($integrationOwnerId, Lead $lead)
    {
        $mauticOwner = $this->getOwnerByIntegrationId($integrationOwnerId);
        $lead->setOwner($mauticOwner);
    }

    /**
     * @param $companyName
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function removeLeadFromCompany($companyName, Lead $lead)
    {
        $company = $this->em->getRepository(Company::class)->findOneByName($companyName);

        if (!$company) {
            return;
        }

        $this->companyModel->removeLeadFromCompany($company, $lead);
    }

    /**
     * @param $integrationCompanyId
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function addLeadToCompany($integrationCompanyId, Lead $lead)
    {
        $integrationEntityCompany = $this->getCompanyIntegrationEntity(['integrationEntityId' => $integrationCompanyId]);

        if (!$integrationEntityCompany) {
            return;
        }

        if (!$company = $this->companyModel->getEntity($integrationEntityCompany->getInternalEntityId())) {
            return;
        }

        $this->companyModel->addLeadToCompany($company, $lead);
    }
}
