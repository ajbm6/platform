<?php

namespace Oro\Bundle\SecurityBundle\Acl\Extension;

use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Exception\InvalidDomainObjectException;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Oro\Bundle\EntityBundle\ORM\EntityClassAccessor;
use Oro\Bundle\SecurityBundle\Acl\Domain\ObjectIdAccessor;
use Oro\Bundle\SecurityBundle\Acl\Extension\OwnershipDecisionMakerInterface;
use Oro\Bundle\EntityBundle\ORM\EntityClassResolver;
use Oro\Bundle\EntityBundle\Owner\Metadata\OwnershipMetadataProvider;
use Oro\Bundle\EntityBundle\Owner\Metadata\OwnershipMetadata;
use Oro\Bundle\SecurityBundle\Acl\Exception\InvalidAclMaskException;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class EntityAclExtension extends AbstractAclExtension
{
    /**
     * @var EntityClassAccessor
     */
    protected $entityClassAccessor;

    /**
     * @var ObjectIdAccessor
     */
    protected $objectIdAccessor;

    /**
     * @var EntityClassResolver
     */
    protected $entityClassResolver;

    /**
     * @var OwnershipMetadataProvider
     */
    protected $metadataProvider;

    /**
     * @var OwnershipDecisionMakerInterface
     */
    protected $decisionMaker;

    /**
     * key = Permission
     * value = The identity of a permission mask builder
     *
     * @var int[]
     */
    protected $permissionToMaskBuilderIdentity = array();

    /**
     * key = The identity of a permission mask builder
     * value = The full class name of a permission mask builder
     *
     * @var string[]
     */
    protected $maskBuilderClassNames = array();

    /**
     * Constructor
     *
     * @param EntityClassAccessor             $entityClassAccessor
     * @param ObjectIdAccessor                $objectIdAccessor
     * @param EntityClassResolver             $entityClassResolver
     * @param OwnershipMetadataProvider       $metadataProvider
     * @param OwnershipDecisionMakerInterface $decisionMaker
     */
    public function __construct(
        EntityClassAccessor $entityClassAccessor,
        ObjectIdAccessor $objectIdAccessor,
        EntityClassResolver $entityClassResolver,
        OwnershipMetadataProvider $metadataProvider,
        OwnershipDecisionMakerInterface $decisionMaker
    ) {
        $this->entityClassAccessor = $entityClassAccessor;
        $this->objectIdAccessor = $objectIdAccessor;
        $this->entityClassResolver = $entityClassResolver;
        $this->metadataProvider = $metadataProvider;
        $this->decisionMaker = $decisionMaker;

        $this->maskBuilderClassNames[EntityMaskBuilder::IDENTITY]
            = 'Oro\Bundle\SecurityBundle\Acl\Extension\EntityMaskBuilder';

        $this->permissionToMaskBuilderIdentity['VIEW'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['CREATE'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['EDIT'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['DELETE'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['ASSIGN'] = EntityMaskBuilder::IDENTITY;
        $this->permissionToMaskBuilderIdentity['SHARE'] = EntityMaskBuilder::IDENTITY;

        $this->map = array(
            'VIEW' => array(
                EntityMaskBuilder::MASK_VIEW_BASIC,
                EntityMaskBuilder::MASK_VIEW_LOCAL,
                EntityMaskBuilder::MASK_VIEW_DEEP,
                EntityMaskBuilder::MASK_VIEW_GLOBAL,
                EntityMaskBuilder::MASK_VIEW_SYSTEM,
            ),
            'CREATE' => array(
                EntityMaskBuilder::MASK_CREATE_BASIC,
                EntityMaskBuilder::MASK_CREATE_LOCAL,
                EntityMaskBuilder::MASK_CREATE_DEEP,
                EntityMaskBuilder::MASK_CREATE_GLOBAL,
                EntityMaskBuilder::MASK_CREATE_SYSTEM,
            ),
            'EDIT' => array(
                EntityMaskBuilder::MASK_EDIT_BASIC,
                EntityMaskBuilder::MASK_EDIT_LOCAL,
                EntityMaskBuilder::MASK_EDIT_DEEP,
                EntityMaskBuilder::MASK_EDIT_GLOBAL,
                EntityMaskBuilder::MASK_EDIT_SYSTEM,
            ),
            'DELETE' => array(
                EntityMaskBuilder::MASK_DELETE_BASIC,
                EntityMaskBuilder::MASK_DELETE_LOCAL,
                EntityMaskBuilder::MASK_DELETE_DEEP,
                EntityMaskBuilder::MASK_DELETE_GLOBAL,
                EntityMaskBuilder::MASK_DELETE_SYSTEM,
            ),
            'ASSIGN' => array(
                EntityMaskBuilder::MASK_ASSIGN_BASIC,
                EntityMaskBuilder::MASK_ASSIGN_LOCAL,
                EntityMaskBuilder::MASK_ASSIGN_DEEP,
                EntityMaskBuilder::MASK_ASSIGN_GLOBAL,
                EntityMaskBuilder::MASK_ASSIGN_SYSTEM,
            ),
            'SHARE' => array(
                EntityMaskBuilder::MASK_SHARE_BASIC,
                EntityMaskBuilder::MASK_SHARE_LOCAL,
                EntityMaskBuilder::MASK_SHARE_DEEP,
                EntityMaskBuilder::MASK_SHARE_GLOBAL,
                EntityMaskBuilder::MASK_SHARE_SYSTEM,
            ),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function supports($type, $id)
    {
        if ($type === $this->getRootType()) {
            $type = $this->entityClassResolver->getEntityClass($this->entityClassAccessor->getClass($id));
            $id = null;
        } else {
            $type = $this->entityClassAccessor->getClass($type);
        }

        // @TODO Add check for service entities (not annotated as ACL)

        $delim = strrpos($type, '\\');
        if ($delim && $this->entityClassResolver->isKnownEntityClassNamespace(substr($type, 0, $delim))) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getRootType()
    {
        return 'entity';
    }

    /**
     * {@inheritdoc}
     */
    public function validateMask($permission, $mask, $object)
    {
        if ($this->removeServiceBits($mask) === $this->permissionToMaskBuilderIdentity[$permission]) {
            return;
        }

        $validMasks = $this->getValidMasks($permission, $object);
        if (($mask | $validMasks) === $validMasks) {
            $identity = $this->permissionToMaskBuilderIdentity[$permission];
            foreach ($this->permissionToMaskBuilderIdentity as $p => $i) {
                if ($identity === $i) {
                    $this->validateMaskAccessLevel($p, $mask, $object);
                }
            }

            return;
        }

        throw $this->createInvalidAclMaskException($permission, $mask, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function createObjectIdentity($object)
    {
        return is_string($object)
            ? $this->fromDescriptor($object)
            : $this->fromDomainObject($object);
    }

    /**
     * {@inheritdoc}
     */
    public function createMaskBuilder($permission)
    {
        $identity = $this->permissionToMaskBuilderIdentity[$permission];
        $maskBuilderClassName = $this->maskBuilderClassNames[$identity];

        return new $maskBuilderClassName();
    }

    /**
     * {@inheritdoc}
     */
    public function getServiceBits($mask)
    {
        return $mask & BaseEntityMaskBuilder::SERVICE_BITS;
    }

    /**
     * {@inheritdoc}
     */
    public function removeServiceBits($mask)
    {
        return $mask & BaseEntityMaskBuilder::REMOVE_SERVICE_BITS;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessLevel($mask)
    {
        if (0 === $this->removeServiceBits($mask)) {
            return AccessLevel::SYSTEM_LEVEL;
        }

        $result = AccessLevel::SYSTEM_LEVEL;
        $identity = $mask & BaseEntityMaskBuilder::SERVICE_BITS;
        foreach (AccessLevel::$allAccessLevelNames as $accessLevel) {
            if (0 !== ($mask & $this->getMaskBuilderConst($identity, 'GROUP_' . $accessLevel))) {
                $result = AccessLevel::getConst($accessLevel . '_LEVEL');
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function decideIsGranting($triggeredMask, $object, TokenInterface $securityToken)
    {
        $accessLevel = $this->getAccessLevel($triggeredMask);
        if ($accessLevel === AccessLevel::SYSTEM_LEVEL) {
            return true;
        }

        // check whether we check permissions for a domain object
        if ($object === null || !is_object($object) || $object instanceof ObjectIdentityInterface) {
            return true;
        }

        $metadata = $this->getMetadata($object);
        if (!$metadata->hasOwner()) {
            return true;
        }

        $result = false;
        if (AccessLevel::BASIC_LEVEL === $accessLevel) {
            $result = $this->decisionMaker->isAssociatedWithUser($securityToken->getUser(), $object);
        } else {
            if ($metadata->isUserOwned()) {
                $result = $this->decisionMaker->isAssociatedWithUser($securityToken->getUser(), $object);
            }
            if (!$result) {
                if (AccessLevel::LOCAL_LEVEL === $accessLevel) {
                    $result = $this->decisionMaker->isAssociatedWithBusinessUnit($securityToken->getUser(), $object);
                } elseif (AccessLevel::DEEP_LEVEL === $accessLevel) {
                    $result = $this->decisionMaker->isAssociatedWithBusinessUnit(
                        $securityToken->getUser(),
                        $object,
                        true
                    );
                } elseif (AccessLevel::GLOBAL_LEVEL === $accessLevel) {
                    $result = $this->decisionMaker->isAssociatedWithOrganization($securityToken->getUser(), $object);
                }
            }
        }

        return $result;
    }

    /**
     * Constructs an ObjectIdentity for the given domain object
     *
     * @param  string                    $descriptor
     * @return ObjectIdentity
     * @throws \InvalidArgumentException
     */
    protected function fromDescriptor($descriptor)
    {
        $type = $id = null;
        $this->parseDescriptor($descriptor, $type, $id);

        if ($type === $this->getRootType()) {
            return new ObjectIdentity(
                $this->entityClassResolver->getEntityClass($this->entityClassAccessor->getClass($id)),
                $this->getRootType()
            );
        }

        throw new \InvalidArgumentException(
            sprintf('Unsupported object identity descriptor: %s.', $descriptor)
        );
    }

    /**
     * Constructs an ObjectIdentity for the given domain object
     *
     * @param  object                       $domainObject
     * @return ObjectIdentity
     * @throws InvalidDomainObjectException
     */
    protected function fromDomainObject($domainObject)
    {
        if (!is_object($domainObject)) {
            throw new InvalidDomainObjectException('$domainObject must be an object.');
        }

        try {
            return new ObjectIdentity(
                $this->objectIdAccessor->getId($domainObject),
                $this->entityClassAccessor->getClass($domainObject)
            );
        } catch (\InvalidArgumentException $invalid) {
            throw new InvalidDomainObjectException($invalid->getMessage(), 0, $invalid);
        }
    }

    /**
     * Checks that the given mask represents only one access level
     *
     * @param  string                  $permission
     * @param  int                     $mask
     * @param  mixed                   $object
     * @throws InvalidAclMaskException
     */
    protected function validateMaskAccessLevel($permission, $mask, $object)
    {
        $identity = $this->permissionToMaskBuilderIdentity[$permission];
        if (0 !== ($mask & $this->getMaskBuilderConst($identity, 'GROUP_' . $permission))) {
            $maskAccessLevels = array();
            foreach (AccessLevel::$allAccessLevelNames as $accessLevel) {
                if (0 !== ($mask & $this->getMaskBuilderConst($identity, 'MASK_' . $permission . '_' . $accessLevel))) {
                    $maskAccessLevels[] = $accessLevel;
                }
            }
            if (count($maskAccessLevels) > 1) {
                $msg = sprintf(
                    'The %s mask must be in one access level only, but it is in %s access levels.',
                    $permission,
                    implode(', ', $maskAccessLevels)
                );
                throw $this->createInvalidAclMaskException($permission, $mask, $object, $msg);
            }
        }
    }

    /**
     * Gets all valid bitmasks for the given object
     *
     * @param  string $permission
     * @param  mixed  $object
     * @return int
     */
    protected function getValidMasks($permission, $object)
    {
        $metadata = $this->getMetadata($object);
        if (!$metadata->hasOwner()) {
            if ($this->permissionToMaskBuilderIdentity[$permission] === EntityMaskBuilder::IDENTITY) {
                return EntityMaskBuilder::GROUP_CRUD_SYSTEM;
            }

            return $this->permissionToMaskBuilderIdentity[$permission];
        }

        $identity = $this->permissionToMaskBuilderIdentity[$permission];
        if ($metadata->isOrganizationOwned()) {
            return
                $this->getMaskBuilderConst($identity, 'GROUP_SYSTEM')
                | $this->getMaskBuilderConst($identity, 'GROUP_GLOBAL');
        } elseif ($metadata->isBusinessUnitOwned()) {
            return
                $this->getMaskBuilderConst($identity, 'GROUP_SYSTEM')
                | $this->getMaskBuilderConst($identity, 'GROUP_GLOBAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_DEEP')
                | $this->getMaskBuilderConst($identity, 'GROUP_LOCAL');
        } elseif ($metadata->isUserOwned()) {
            return
                $this->getMaskBuilderConst($identity, 'GROUP_SYSTEM')
                | $this->getMaskBuilderConst($identity, 'GROUP_GLOBAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_DEEP')
                | $this->getMaskBuilderConst($identity, 'GROUP_LOCAL')
                | $this->getMaskBuilderConst($identity, 'GROUP_BASIC');
        }

        return $this->permissionToMaskBuilderIdentity[$permission];
    }

    /**
     * Gets metadata for the given object
     *
     * @param  mixed             $object
     * @return OwnershipMetadata
     */
    protected function getMetadata($object)
    {
        if ($object instanceof ObjectIdentity) {
            $className = $object->getType();
        } elseif (is_string($object)) {
            $sortOfDescriptor = $className = null;
            $this->parseDescriptor($object, $sortOfDescriptor, $className);
        } else {
            $className = $this->entityClassAccessor->getClass($object);
        }

        return $this->metadataProvider->getMetadata($className);
    }

    /**
     * Gets the constant value defined in the given permission mask builder
     *
     * @param  int    $maskBuilderIdentity The permission mask builder identity
     * @param  string $constName
     * @return int
     */
    protected function getMaskBuilderConst($maskBuilderIdentity, $constName)
    {
        $maskBuilderClassName = $this->maskBuilderClassNames[$maskBuilderIdentity];

        return $maskBuilderClassName::getConst($constName);
    }

    /**
     * Gets a human-readable representation of the given mask using the given permission mask builder
     *
     * @param  int    $maskBuilderIdentity The permission mask builder identity
     * @param  int    $mask
     * @return string
     */
    protected function getMaskPattern($maskBuilderIdentity, $mask)
    {
        $maskBuilderClassName = $this->maskBuilderClassNames[$maskBuilderIdentity];

        return $maskBuilderClassName::getPatternFor($mask);
    }
}