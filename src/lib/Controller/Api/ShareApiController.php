<?php
/**
 * Created by PhpStorm.
 * User: marius
 * Date: 29.12.17
 * Time: 18:32
 */

namespace OCA\Passwords\Controller\Api;

use OCA\Passwords\Db\Password;
use OCA\Passwords\Db\PasswordRevision;
use OCA\Passwords\Db\ShareRevision;
use OCA\Passwords\Exception\ApiException;
use OCA\Passwords\Helper\ApiObjects\ShareObjectHelper;
use OCA\Passwords\Services\EncryptionService;
use OCA\Passwords\Services\Object\PasswordRevisionService;
use OCA\Passwords\Services\Object\PasswordService;
use OCA\Passwords\Services\Object\ShareRevisionService;
use OCA\Passwords\Services\Object\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;

/**
 * Class ShareApiController
 *
 * @package OCA\Passwords\Controller\Api
 */
class ShareApiController extends AbstractApiController {

    const USER_SEARCH_LIMIT = 512;

    /**
     * @var IUser
     */
    protected $user;

    /**
     * @var string
     */
    protected $userId;

    /**
     * @var IConfig
     */
    protected $config;

    /**
     * @var IUserManager
     */
    protected $userManager;
    /**
     * @var ShareService
     */
    protected $modelService;

    /**
     * @var IManager
     */
    protected $shareManager;

    /**
     * @var IGroupManager
     */
    protected $groupManager;

    /**
     * @var PasswordService
     */
    protected $passwordModelService;

    /**
     * @var PasswordRevisionService
     */
    protected $passwordRevisionService;

    /**
     * TagApiController constructor.
     *
     * @param string                  $appName
     * @param IUser                   $user
     * @param IConfig                 $config
     * @param IRequest                $request
     * @param IManager                $shareManager
     * @param IUserManager            $userManager
     * @param ShareService            $modelService
     * @param IGroupManager           $groupManager
     * @param PasswordService         $passwordModelService
     * @param PasswordRevisionService $passwordRevisionService
     */
    public function __construct(
        string $appName,
        IUser $user,
        IConfig $config,
        IRequest $request,
        IManager $shareManager,
        IUserManager $userManager,
        ShareService $modelService,
        IGroupManager $groupManager,
        PasswordService $passwordModelService,
        PasswordRevisionService $passwordRevisionService
    ) {
        parent::__construct(
            $appName,
            $request,
            'PUT, POST, GET, DELETE, PATCH',
            'Authorization, Content-Type, Accept',
            1728000
        );

        $this->user                    = $user;
        $this->config                  = $config;
        $this->userId                  = $user->getUID();
        $this->userManager             = $userManager;
        $this->groupManager            = $groupManager;
        $this->modelService            = $modelService;
        $this->shareManager            = $shareManager;
        $this->passwordModelService    = $passwordModelService;
        $this->passwordRevisionService = $passwordRevisionService;
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @param string   $password
     * @param string   $receiver
     * @param string   $type
     * @param int|null $expires
     * @param bool     $editable
     * @param bool     $shareable
     *
     * @return JSONResponse
     */
    public function create(
        string $password,
        string $receiver,
        string $type = 'user',
        int $expires = null,
        bool $editable = false,
        bool $shareable = false
    ): JSONResponse {
        try {
            $this->checkAccessPermissions();

            $partners = $this->getSharePartners('');
            if(!isset($partners[ $receiver ])) throw new ApiException('Invalid receiver uid', 403);

            if(empty($expires)) $expires = null;
            if($expires !== null && $expires < time()) {
                throw new ApiException('Invalid expiration date', 400);
            }
            if($type !== 'user') {
                throw new ApiException('Invalid share type', 400);
            }

            /** @var Password $model */
            $model = $this->passwordModelService->findByUuid($password);
            if($model->getShareId()) {
                $sourceShare = $this->modelService->findByUuid($model->getShareId());
                $reSharing   = $this->config->getAppValue('core', 'shareapi_allow_resharing', 'yes') === 'yes';
                if(!$sourceShare->isShareable() || !$reSharing) {
                    throw new ApiException('Sharing not allowed', 403);
                }
                if(!$sourceShare->isEditable()) $editable = false;
            }

            $shares = $this->modelService->findBySourcePasswordAndReceiver($model->getUuid(), $receiver);
            if($shares !== null) {
                throw new ApiException('Password already shared with user', 420);
            }

            /** @var PasswordRevision $revision */
            $revision = $this->passwordRevisionService->findByUuid($model->getRevision(), true);

            if($revision->getCseType() !== EncryptionService::CSE_ENCRYPTION_NONE) {
                throw new ApiException('CSE type does not support sharing', 420);
            }

            if($revision->getSseType() !== EncryptionService::SSE_ENCRYPTION_V1) {
                $revision = $this->passwordRevisionService->clone(
                    $revision,
                    ['sseType' => EncryptionService::SSE_ENCRYPTION_V1]
                );
                $this->passwordRevisionService->save($revision);
                $this->passwordModelService->setRevision($model, $revision);
            }

            $share = $this->modelService->create($model->getUuid(), $receiver, $type, $editable, $expires, $shareable);
            $this->modelService->save($share);

            if(!$model->hasShares()) {
                $model->setHasShares(true);
                $this->passwordModelService->save($model);
            }

            return $this->createJsonResponse(
                ['id' => $share->getUuid()], Http::STATUS_CREATED
            );
        } catch (\Throwable $e) {
            return $this->createErrorResponse($e);
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @param string   $id
     * @param int|null $expires
     * @param bool     $editable
     * @param bool     $shareable
     *
     * @return JSONResponse
     */
    public function update(string $id, int $expires = null, bool $editable = false, bool $shareable = true): JSONResponse {

        try {
            $this->checkAccessPermissions();

            if(empty($expires)) $expires = null;
            if($expires !== null && $expires < time()) {
                throw new ApiException('Invalid expiration date', 400);
            }

            $share = $this->modelService->findByUuid($id);
            if($share->getUserId() !== $this->userId) {
                throw new ApiException('Access denied', 403);
            }

            $share->setExpires($expires);
            $share->setEditable($editable);
            $share->setShareable($shareable);
            $share->setSourceUpdated(true);
            $this->modelService->save($share);

            return $this->createJsonResponse(['id' => $share->getUuid()]);
        } catch (\Throwable $e) {
            return $this->createErrorResponse($e);
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @param string $id
     *
     * @return JSONResponse
     */
    public function delete(string $id): JSONResponse {
        try {
            $this->checkAccessPermissions();
            $model = $this->modelService->findByUuid($id);
            if($model->getUserId() !== $this->userId) {
                throw new ApiException('Access denied', 403);
            }

            $this->modelService->delete($model);

            return $this->createJsonResponse(['id' => $model->getUuid()]);
        } catch (\Throwable $e) {
            return $this->createErrorResponse($e);
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function info(): JSONResponse {
        try {
            $this->checkAccessPermissions();

            $info = [
                'enabled'   => $this->shareManager->shareApiEnabled() &&
                               !$this->shareManager->sharingDisabledForUser($this->userId),
                'resharing' => $this->config->getAppValue('core', 'shareapi_allow_resharing', 'yes') === 'yes',
                'types'     => ['user']
            ];

            return $this->createJsonResponse($info);
        } catch (\Throwable $e) {
            return $this->createErrorResponse($e);
        }
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @param string $search
     *
     * @return JSONResponse
     */
    public function partners(string $search = ''): JSONResponse {
        try {
            $this->checkAccessPermissions();

            $partners = [];
            if($this->config->getAppValue('core', 'shareapi_allow_share_dialog_user_enumeration', 'no') === 'yes') {
                $partners = $this->getSharePartners($search);
            }

            return $this->createJsonResponse($partners);
        } catch (\Throwable $e) {
            return $this->createErrorResponse($e);
        }
    }

    /**
     * @param string $pattern
     *
     * @return array
     */
    protected function getSharePartners(string $pattern): array {
        $partners = [];
        if($this->shareManager->shareWithGroupMembersOnly()) {
            $userGroups = $this->groupManager->getUserGroupIds($this->user);
            foreach ($userGroups as $userGroup) {
                $users = $this->groupManager->displayNamesInGroup($userGroup, $pattern, self::USER_SEARCH_LIMIT);
                foreach ($users as $uid => $name) {
                    if($uid == $this->userId) continue;
                    $partners[ $uid ] = $name;
                }
                if(count($partners) >= self::USER_SEARCH_LIMIT) break;
            }
        } else {
            $usersTmp = $this->userManager->search($pattern, self::USER_SEARCH_LIMIT);

            foreach ($usersTmp as $user) {
                if($user->getUID() == $this->userId) continue;
                $partners[ $user->getUID() ] = $user->getDisplayName();
            }
        }

        return $partners;
    }

    /**
     * @throws ApiException
     */
    protected function checkAccessPermissions(): void {
        if(!$this->shareManager->shareApiEnabled()) {
            throw new ApiException('Sharing disabled', 403);
        }
        if($this->shareManager->sharingDisabledForUser($this->userId)) {
            throw new ApiException('Sharing disabled for user', 403);
        }

        parent::checkAccessPermissions();
    }
}