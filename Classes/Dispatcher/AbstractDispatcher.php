<?php
declare(strict_types=1);

namespace SourceBroker\T3api\Dispatcher;

use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use SourceBroker\T3api\Domain\Model\AbstractOperation;
use SourceBroker\T3api\Domain\Model\CollectionOperation;
use SourceBroker\T3api\Domain\Model\ItemOperation;
use SourceBroker\T3api\Domain\Repository\ApiResourceRepository;
use SourceBroker\T3api\Domain\Repository\CommonRepository;
use SourceBroker\T3api\Response\AbstractCollectionResponse;
use SourceBroker\T3api\Service\SerializerService;
use SourceBroker\T3api\Service\ValidationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Class AbstractDispatcher
 */
abstract class AbstractDispatcher
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var SerializerService
     */
    protected $serializerService;

    /**
     * @var ApiResourceRepository
     */
    protected $apiResourceRepository;

    /**
     * @var ValidationService
     */
    protected $validationService;

    /**
     * Bootstrap constructor.
     */
    public function __construct()
    {
        $this->objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->serializerService = $this->objectManager->get(SerializerService::class);
        $this->apiResourceRepository = $this->objectManager->get(ApiResourceRepository::class);
        $this->validationService = $this->objectManager->get(ValidationService::class);
    }

    /**
     * @param RequestContext $requestContext
     * @param Request $request
     * @param ResponseInterface $response
     *
     * @return string
     * @throws Exception
     * @throws RouteNotFoundException
     */
    public function processOperationByRequest(
        RequestContext $requestContext,
        Request $request,
        ResponseInterface &$response = null
    ): string {
        foreach ($this->apiResourceRepository->getAll() as $apiResource) {
            try {
                $matchedRoute = (new UrlMatcher($apiResource->getRoutes(), $requestContext))
                    ->matchRequest($request);

                return $this->processOperation(
                    $apiResource->getOperationByRouteName($matchedRoute['_route']),
                    $matchedRoute,
                    $request,
                    $response
                );
            } catch (ResourceNotFoundException $resourceNotFoundException) {
                // @todo add comment
            }
        }

        throw new RouteNotFoundException('T3api resource not found for current route', 1557217186441);
    }

    /**
     * @param AbstractOperation $operation
     * @param array $matchedRoute
     * @param Request $request
     * @param ResponseInterface $response
     *
     * @return string
     * @throws Exception
     */
    protected function processOperation(
        AbstractOperation $operation,
        array $matchedRoute,
        Request $request,
        ResponseInterface &$response = null
    ): string {
        if ($operation instanceof ItemOperation) {
            $result = $this->processItemOperation($operation, (int)$matchedRoute['id'], $request, $response);
        } elseif ($operation instanceof CollectionOperation) {
            $result = $this->processCollectionOperation($operation, $request, $response);
        } else {
            // @todo throw appropriate exception
            throw new Exception('Unknown operation', 1557506987081);
        }

        return $this->serializerService->serializeOperation($operation, $result);
    }

    /**
     * @param ItemOperation $operation
     * @param int $uid
     * @param Request $request
     * @param ResponseInterface $response
     *
     * @return AbstractDomainObject
     *
     * @throws InvalidArgumentException
     */
    protected function processItemOperation(
        ItemOperation $operation,
        int $uid,
        Request $request,
        ResponseInterface &$response = null
    ): AbstractDomainObject {
        $repository = CommonRepository::getInstanceForResource($operation->getApiResource());

        /** @var AbstractDomainObject $object */
        $object = $repository->findByUid($uid);

        if ($operation->getMethod() !== 'GET') {
            throw new InvalidArgumentException(
                sprintf('Method `%s` is not supported for item operation', $operation->getMethod()),
                1568378714606
            );
        } elseif ($operation->getMethod() === 'PUT' || $operation->getMethod() === 'PATCH') {
            // @todo 591 implement support for PUT/PATCH
            // @todo 591 validation
            // https://stackoverflow.com/questions/52114926/symfonyjms-serializer-deserialize-into-existing-object
            // https://github.com/schmittjoh/serializer/issues/79
            die('PUT request');
        } elseif ($operation->getMethod() === 'DELETE') {
            // @todo 591 implement support for DELETE
            die('DELETE request');
        } else {
            // @todo 591
            // throw new InvalidArgumentException();
            // @todo 591 status code 405
        }

        if (!$object) {
            // @todo throw appropriate exception
            throw new InvalidArgumentException('Item not found');
        }

        return $object;
    }

    /**
     * @param CollectionOperation $operation
     * @param Request $request
     * @param ResponseInterface $response
     *
     * @return AbstractDomainObject|AbstractCollectionResponse
     * @throws \TYPO3\CMS\Core\Cache\Exception
     * @throws \TYPO3\CMS\Extbase\Validation\Exception
     */
    protected function processCollectionOperation(
        CollectionOperation $operation,
        Request $request,
        ResponseInterface &$response = null
    ) {
        $repository = CommonRepository::getInstanceForResource($operation->getApiResource());

        if ($operation->getMethod() === 'GET') {
            return $this->objectManager->get(
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3api']['collectionResponseClass'],
                $operation,
                $request,
                $repository->findFiltered($operation->getFilters(), $request)
            );
        } elseif ($operation->getMethod() === 'POST') {
            $object = $this->serializerService->deserializeOperation($operation, $request->getContent());
            $this->validationService->validateObject($object);
            $repository->add($object);
            $this->objectManager->get(PersistenceManager::class)->persistAll();

            $response = $response->withStatus(201);

            return $object;
        } else {
            // @todo 591 throw appropriate exception
            // @todo 591 status code 405
        }
    }
}
