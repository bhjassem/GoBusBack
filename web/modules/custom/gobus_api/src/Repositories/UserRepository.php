<?php

namespace Drupal\gobus_api\Repositories;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\simple_oauth\Entities\UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

/**
 * User repository implementation.
 */
class UserRepository implements UserRepositoryInterface
{

    /**
     * The entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * The password hashing service.
     *
     * @var \Drupal\Core\Password\PasswordInterface
     */
    protected $passwordHasher;

    /**
     * User repository constructor.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\Core\Password\PasswordInterface $password_hasher
     *   The password hashing service.
     */
    public function __construct(EntityTypeManagerInterface $entity_type_manager, PasswordInterface $password_hasher)
    {
        $this->entityTypeManager = $entity_type_manager;
        $this->passwordHasher = $password_hasher;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserEntityByUserCredentials(string $username, string $password, string $grantType, ClientEntityInterface $clientEntity): ?UserEntityInterface
    {
        // Basic validation.
        if (empty($username) || empty($password)) {
            return null;
        }

        // Load user by name.
        $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $username, 'status' => 1]);
        $user = reset($users);

        if ($user) {
            if ($this->passwordHasher->check($password, $user->getPassword())) {
                $user_entity = new UserEntity();
                $user_entity->setIdentifier($user->id());
                return $user_entity;
            }
        }

        return null;
    }

}