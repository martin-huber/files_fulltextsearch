<?php
declare(strict_types=1);


/**
 * Files_FullTextSearch - Index the content of your files
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Files_FullTextSearch\Service;


use daita\MySmallPhpTools\Traits\TPathTools;
use Exception;
use OC\FullTextSearch\Model\DocumentAccess;
use OCA\Files_FullTextSearch\Exceptions\EmptyUserException;
use OCA\Files_FullTextSearch\Exceptions\FileIsNotIndexableException;
use OCA\Files_FullTextSearch\Exceptions\FilesNotFoundException;
use OCA\Files_FullTextSearch\Exceptions\KnownFileMimeTypeException;
use OCA\Files_FullTextSearch\Exceptions\KnownFileSourceException;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Provider\FilesProvider;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use OCP\Comments\ICommentsManager;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\FullTextSearch\Model\IRunner;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use Throwable;


/**
 * Class FilesService
 *
 * @package OCA\Files_FullTextSearch\Service
 */
class FilesService {


	use TPathTools;


	const MIMETYPE_TEXT = 'files_text';
	const MIMETYPE_PDF = 'files_pdf';
	const MIMETYPE_OFFICE = 'files_office';
	const MIMETYPE_ZIP = 'files_zip';
	const MIMETYPE_IMAGE = 'files_image';
	const MIMETYPE_AUDIO = 'files_audio';

	const CHUNK_TREE_SIZE = 2;


	/** @var IAppContainer */
	private $container;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IUserManager */
	private $userManager;

	/** @var IAppManager */
	private $appManager;

	/** @var IShareManager */
	private $shareManager;

	/** @var ICommentsManager */
	private $commentsManager;

	/** @var ConfigService */
	private $configService;

	/** @var LocalFilesService */
	private $localFilesService;

	/** @var ExternalFilesService */
	private $externalFilesService;

	/** @var GroupFoldersService */
	private $groupFoldersService;

	/** @var ExtensionService */
	private $extensionService;

	/** @var IFullTextSearchManager */
	private $fullTextSearchManager;

	/** @var MiscService */
	private $miscService;


	/** @var IRunner */
	private $runner;

	/** @var int */
	private $sumDocuments;


	/**
	 * FilesService constructor.
	 *
	 * @param IAppContainer $container
	 * @param IRootFolder $rootFolder
	 * @param IAppManager $appManager
	 * @param IUserManager $userManager
	 * @param IShareManager $shareManager
	 * @param ICommentsManager $commentsManager
	 * @param ConfigService $configService
	 * @param LocalFilesService $localFilesService
	 * @param ExternalFilesService $externalFilesService
	 * @param GroupFoldersService $groupFoldersService
	 * @param ExtensionService $extensionService
	 * @param IFullTextSearchManager $fullTextSearchManager
	 * @param MiscService $miscService
	 *
	 * @internal param IProviderFactory $factory
	 */
	public function __construct(
		IAppContainer $container, IRootFolder $rootFolder, IAppManager $appManager,
		IUserManager $userManager, IShareManager $shareManager, ICommentsManager $commentsManager,
		ConfigService $configService,
		LocalFilesService $localFilesService, ExternalFilesService $externalFilesService,
		GroupFoldersService $groupFoldersService, ExtensionService $extensionService,
		IFullTextSearchManager $fullTextSearchManager, MiscService $miscService
	) {
		$this->container = $container;
		$this->rootFolder = $rootFolder;
		$this->appManager = $appManager;
		$this->userManager = $userManager;
		$this->shareManager = $shareManager;
		$this->commentsManager = $commentsManager;

		$this->configService = $configService;
		$this->localFilesService = $localFilesService;
		$this->externalFilesService = $externalFilesService;
		$this->groupFoldersService = $groupFoldersService;
		$this->extensionService = $extensionService;
		$this->fullTextSearchManager = $fullTextSearchManager;

		$this->miscService = $miscService;
	}


	/**
	 * @param IRunner $runner
	 */
	public function setRunner(IRunner $runner) {
		$this->runner = $runner;
	}


	/**
	 * @param string $userId
	 * @param IIndexOptions $indexOptions
	 *
	 * @return FilesDocument[]
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 */
	public function getChunksFromUser(string $userId, IIndexOptions $indexOptions): array {
		$this->initFileSystems($userId);

		/** @var Folder $files */
		$files = $this->rootFolder->getUserFolder($userId)
								  ->get($indexOptions->getOption('path', '/'));
		if ($files instanceof Folder) {
			return $this->getChunksFromDirectory($userId, $files);
		} else {
			return [$files];
		}
	}


	/**
	 * @param string $userId
	 * @param Folder $node
	 * @param int $level
	 *
	 * @return FilesDocument[]
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	private function getChunksFromDirectory(string $userId, Folder $node, $level = 0): array {
		$entries = [];
		$level++;

		$files = $node->getDirectoryListing();
		if (empty($files)) {
			$entries[] = $this->getPathFromRoot($node->getPath(), $userId, true);
		}

		foreach ($files as $file) {
			if ($file->getType() === FileInfo::TYPE_FOLDER && $level < self::CHUNK_TREE_SIZE) {
				/** @var $file Folder */
				$entries =
					array_merge($entries, $this->getChunksFromDirectory($userId, $file, $level));
			} else {
				$entries[] = $this->getPathFromRoot($file->getPath(), $userId, true);
			}
		}

		return $entries;
	}


	/**
	 * @param string $userId
	 * @param string $chunk
	 *
	 * @return FilesDocument[]
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function getFilesFromUser(string $userId, string $chunk): array {

		$this->initFileSystems($userId);
		$this->sumDocuments = 0;

		/** @var Folder $files */
		$files = $this->rootFolder->getUserFolder($userId)
								  ->get($chunk);

		$result = [];
		if ($files instanceof Folder) {
			$result = $this->generateFilesDocumentFromParent($userId, $files);
			$result = array_merge($result, $this->getFilesFromDirectory($userId, $files));
		} else {
			try {
				$result[] = $this->generateFilesDocumentFromFile($userId, $files);
			} catch (FileIsNotIndexableException $e) {
				/** we do nothin' */
			}
		}

		return $result;
	}


	/**
	 * @param string $userId
	 * @param Folder $node
	 *
	 * @return FilesDocument[]
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function getFilesFromDirectory(string $userId, Folder $node): array {
		$documents = [];

		$this->updateRunnerAction('generateIndexFiles', true);
		$this->updateRunnerInfo(
			[
				'info'          => $node->getPath(),
				'title'         => '',
				'content'       => '',
				'documentTotal' => $this->sumDocuments
			]
		);

		try {
			if ($node->nodeExists('.noindex')) {
				return $documents;
			}
		} catch (StorageNotAvailableException $e) {
			return $documents;
		}

		if ($this->configService->getAppValue(ConfigService::FILES_EXTERNAL) === '2'
			&& $node->getMountPoint()
					->getMountType() === 'external') {
			return $documents;
		}

		$files = $node->getDirectoryListing();
		foreach ($files as $file) {

			try {
				$documents[] = $this->generateFilesDocumentFromFile($userId, $file);
				$this->sumDocuments++;
			} catch (FileIsNotIndexableException $e) {
				continue;
			}

			if ($file->getType() === FileInfo::TYPE_FOLDER) {
				/** @var $file Folder */
				$documents =
					array_merge($documents, $this->getFilesFromDirectory($userId, $file));
			}
		}

		return $documents;
	}


	/**
	 * @param string $userId
	 */
	private function initFileSystems(string $userId) {
		if ($userId === '') {
			return;
		}

		if ($this->userManager->get($userId) === null) {
			return;
		}

		$this->externalFilesService->initExternalFilesForUser($userId);
		$this->groupFoldersService->initGroupSharesForUser($userId);
	}


	/**
	 * @param string $userId
	 * @param Folder $parent
	 *
	 * @return array
	 */
	private function generateFilesDocumentFromParent(string $userId, Folder $parent): array {
		$documents = [];
		try {
			for ($i = 0; $i < self::CHUNK_TREE_SIZE; $i++) {
				$parent = $parent->getParent();
				$documents[] = $this->generateFilesDocumentFromFile($userId, $parent);
			}
		} catch (Exception $e) {
		}

		return $documents;
	}


	/**
	 * @param string $viewerId
	 * @param Node $file
	 *
	 * @return FilesDocument
	 * @throws FileIsNotIndexableException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws Exception
	 */
	private function generateFilesDocumentFromFile(string $viewerId, Node $file): FilesDocument {

		$this->isNodeIndexable($file);

		$source = $this->getFileSource($file);
		$document = new FilesDocument(FilesProvider::FILES_PROVIDER_ID, (string)$file->getId());
		$document->setAccess(new DocumentAccess());

		if ($file->getId() === -1) {
			throw new FileIsNotIndexableException();
		}

		$ownerId = '';
		if ($file->getOwner() !== null) {
			$ownerId = $file->getOwner()
							->getUID();
		}

		if (!is_string($ownerId)) {
			$ownerId = '';
		}

		$document->setType($file->getType())
				 ->setOwnerId($ownerId)
				 ->setPath($this->getPathFromViewerId($file->getId(), $viewerId))
				 ->setViewerId($viewerId)
				 ->setMimetype($file->getMimetype());
		$document->setModifiedTime($file->getMTime())
				 ->setSource($source);

		return $document;
	}


	/**
	 * @param Node $file
	 *
	 * @return string
	 * @throws FileIsNotIndexableException
	 */
	private function getFileSource(Node $file): string {
		$source = '';

		try {
			$this->localFilesService->getFileSource($file, $source);
			$this->externalFilesService->getFileSource($file, $source);
			$this->groupFoldersService->getFileSource($file, $source);
		} catch (KnownFileSourceException $e) {
			/** we know the source, just leave. */
		}

		return $source;
	}


	/**
	 * @param string $userId
	 * @param string $path
	 *
	 * @return Node
	 * @throws NotFoundException
	 */
	public function getFileFromPath(string $userId, string $path): Node {
		return $this->rootFolder->getUserFolder($userId)
								->get($path);
	}


	/**
	 * @param string $userId
	 * @param int $fileId
	 *
	 * @return Node
	 * @throws FilesNotFoundException
	 * @throws EmptyUserException
	 */
	public function getFileFromId(string $userId, int $fileId): Node {
		if ($userId === '') {
			throw new EmptyUserException();
		}

		$files = $this->rootFolder->getUserFolder($userId)
								  ->getById($fileId);
		if (sizeof($files) === 0) {
			throw new FilesNotFoundException();
		}

		$file = array_shift($files);

		return $file;
	}


	/**
	 * @param IIndex $index
	 *
	 * @return Node
	 * @throws EmptyUserException
	 * @throws FilesNotFoundException
	 */
	public function getFileFromIndex(IIndex $index): Node {
		// it seems the method is already call slightly earlier in the process
//		$this->impersonateOwner($index);

		return $this->getFileFromId($index->getOwnerId(), (int)$index->getDocumentId());
	}


	/**
	 * @param int $fileId
	 * @param string $viewerId
	 *
	 * @return string
	 * @throws Exception
	 */
	private function getPathFromViewerId(int $fileId, string $viewerId): string {

		$viewerFiles = $this->rootFolder->getUserFolder($viewerId)
										->getById($fileId);

		if (sizeof($viewerFiles) === 0) {
			return '';
		}

		$file = array_shift($viewerFiles);

		// TODO: better way to do this : we remove the '/userid/files/'
		$path = $this->getPathFromRoot($file->getPath(), $viewerId);
		if (!is_string($path)) {
			throw new FileIsNotIndexableException();
		}

		$path = $this->withoutEndSlash($path);

		return $path;
	}


	/**
	 * @param FilesDocument $document
	 */
	public function generateDocument(FilesDocument $document) {

		try {
			$this->updateFilesDocument($document);
		} catch (Exception $e) {
			// TODO - update $document with a error status instead of just ignore !
			$document->getIndex()
					 ->setStatus(IIndex::INDEX_IGNORE);
			$this->miscService->log(
				'Exception while generateDocument: ' . $e->getMessage() . ' - trace: '
				. json_encode($e->getTrace())
			);
		}
	}


	/**
	 * @param IIndex $index
	 *
	 * @return FilesDocument
	 * @throws FileIsNotIndexableException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	private function generateDocumentFromIndex(IIndex $index): FilesDocument {

		try {
			$file = $this->getFileFromIndex($index);

			if ($file->getMountPoint()
					 ->getMountType() === 'external'
				&& $this->configService->getAppValue(ConfigService::FILES_EXTERNAL) === '2') {
				throw new Exception();
			}
		} catch (Exception $e) {
			$index->setStatus(IIndex::INDEX_REMOVE);
			$document = new FilesDocument($index->getProviderId(), $index->getDocumentId());
			$document->setIndex($index);

			return $document;
		}

		$this->isNodeIndexable($file);

		$document = $this->generateFilesDocumentFromFile($index->getOwnerId(), $file);
		$document->setIndex($index);

		$this->updateFilesDocumentFromFile($document, $file);

		return $document;
	}


	/**
	 * @param IIndexDocument $document
	 *
	 * @return bool
	 */
	public function isDocumentUpToDate(IIndexDocument $document): bool {
		$this->extensionService->indexComparing($document);

		$index = $document->getIndex();


		if (!$this->configService->compareIndexOptions($index)) {
			$index->setStatus(IIndex::INDEX_CONTENT);
			$document->setIndex($index);

			return false;
		}

		if ($index->getStatus() !== IIndex::INDEX_OK) {
			return false;
		}

		if ($index->getLastIndex() >= $document->getModifiedTime()) {
			return true;
		}

		return false;
	}


	/**
	 * @param IIndex $index
	 *
	 * @return FilesDocument
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws FileIsNotIndexableException
	 */
	public function updateDocument(IIndex $index): FilesDocument {
		$this->impersonateOwner($index);
		$this->initFileSystems($index->getOwnerId());

		$document = $this->generateDocumentFromIndex($index);
		$this->updateDirectoryContentIndex($index);

		return $document;
	}


	/**
	 * @param FilesDocument $document
	 *
	 * @throws NotFoundException
	 */
	private function updateFilesDocument(FilesDocument $document) {
		$userFolder = $this->rootFolder->getUserFolder($document->getViewerId());
		$file = $userFolder->get($document->getPath());

		try {
			$this->updateFilesDocumentFromFile($document, $file);
			if ($file->getType() ===  FileInfo::TYPE_FOLDER) {
                $document->getIndex()->setStatus(IIndex::INDEX_IGNORE);
            }
		} catch (FileIsNotIndexableException $e) {
			$document->getIndex()
					 ->setStatus(IIndex::INDEX_IGNORE);
		}
	}


	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 *
	 * @throws FileIsNotIndexableException
	 */
	private function updateFilesDocumentFromFile(FilesDocument $document, Node $file) {

		$document->getIndex()
				 ->setSource($document->getSource());

		$this->updateDocumentAccess($document, $file);
		$this->updateContentFromFile($document, $file);

		$document->addMetaTag($document->getSource());
	}


	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 *
	 * @throws FileIsNotIndexableException
	 */
	private function updateDocumentAccess(FilesDocument $document, Node $file) {

//		$index = $document->getIndex();
		// This should not be needed, let's assume we _need_ to update document access
//		if (!$index->isStatus(IIndex::INDEX_FULL)
//			&& !$index->isStatus(IIndex::INDEX_META)) {
//			return;
//		}

		$this->localFilesService->updateDocumentAccess($document, $file);
		$this->externalFilesService->updateDocumentAccess($document, $file);
		$this->groupFoldersService->updateDocumentAccess($document, $file);

		$this->updateShareNames($document, $file);
	}


	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 */
	private function updateContentFromFile(FilesDocument $document, Node $file) {

		$document->setTitle($document->getPath());

		if ((!$document->getIndex()
					   ->isStatus(IIndex::INDEX_CONTENT)
			 && !$document->getIndex()
						  ->isStatus(IIndex::INDEX_META)
			)
			|| $file->getType() !== FileInfo::TYPE_FILE) {
			return;
		}

		try {
			/** @var File $file */
			if ($file->getSize() <
				($this->configService->getAppValue(ConfigService::FILES_SIZE) * 1024 * 1024)) {
				$this->extractContentFromFileText($document, $file);
				$this->extractContentFromFileOffice($document, $file);
				$this->extractContentFromFilePDF($document, $file);
				$this->extractContentFromFileZip($document, $file);

				$this->extensionService->fileIndexing($document, $file);
			}
		} catch (Throwable $t) {
			$this->manageContentErrorException($document, $t);
		}

		if ($document->getContent() === null) {
			$document->getIndex()
					 ->unsetStatus(IIndex::INDEX_CONTENT);
		}

		$this->updateCommentsFromFile($document);
	}


	/**
	 * @param FilesDocument $document
	 */
	private function updateCommentsFromFile(FilesDocument $document) {
		$comments = $this->commentsManager->getForObject('files', $document->getId());

		$part = [];
		foreach ($comments as $comment) {
			$part[] = '<' . $comment->getActorId() . '> ' . $comment->getMessage();
		}

		$document->addPart('comments', implode(" \n ", $part));
	}


	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 *
	 * @return array
	 */
	private function updateShareNames(FilesDocument $document, Node $file): array {

		$users = [];

		$this->localFilesService->getShareUsersFromFile($file, $users);
		$this->externalFilesService->getShareUsers($document, $users);
		$this->groupFoldersService->getShareUsers($document, $users);

		$shareNames = [];
		foreach ($users as $username) {
			$username = (string)$username;

			try {
				$user = $this->userManager->get($username);
				if ($user === null || $user->getLastLogin() === 0) {
					continue;
				}

				$path = $this->getPathFromViewerId($file->getId(), $username);
				$shareNames[$this->miscService->secureUsername($username)] =
					(!is_string($path)) ? $path = '' : $path;

			} catch (Exception $e) {
				$this->miscService->log(
					'Issue while getting information on documentId:' . $document->getId(), 0
				);
			}
		}

		$document->setInfoArray('share_names', $shareNames);

		return $shareNames;
	}


	/**
	 * @param string $mimeType
	 * @param string $extension
	 *
	 * @return string
	 */
	private function parseMimeType(string $mimeType, string $extension): string {

		$parsed = '';
		try {
			$this->parseMimeTypeText($mimeType, $extension, $parsed);
			$this->parseMimeTypePDF($mimeType, $parsed);
			$this->parseMimeTypeOffice($mimeType, $parsed);
			$this->parseMimeTypeZip($mimeType, $parsed);
		} catch (KnownFileMimeTypeException $e) {
		}

		return $parsed;
	}


	/**
	 * @param string $mimeType
	 * @param string $extension
	 * @param string $parsed
	 *
	 * @throws KnownFileMimeTypeException
	 */
	private function parseMimeTypeText(string $mimeType, string $extension, string &$parsed) {

		if (substr($mimeType, 0, 5) === 'text/') {
			$parsed = self::MIMETYPE_TEXT;
			throw new KnownFileMimeTypeException();
		}

		$textMimes = [
			'application/epub+zip'
		];

		foreach ($textMimes as $mime) {
			if (strpos($mimeType, $mime) === 0) {
				$parsed = self::MIMETYPE_TEXT;
				throw new KnownFileMimeTypeException();
			}
		}

		$this->parseMimeTypeTextByExtension($mimeType, $extension, $parsed);
	}


	/**
	 * @param string $mimeType
	 * @param string $extension
	 * @param string $parsed
	 *
	 * @throws KnownFileMimeTypeException
	 */
	private function parseMimeTypeTextByExtension(
		string $mimeType, string $extension, string &$parsed
	) {
		$textMimes = [
			'application/octet-stream'
		];
		$textExtension = [
		];

		foreach ($textMimes as $mime) {
			if (strpos($mimeType, $mime) === 0
				&& in_array(
					strtolower($extension), $textExtension
				)) {
				$parsed = self::MIMETYPE_TEXT;
				throw new KnownFileMimeTypeException();
			}
		}
	}


	/**
	 * @param string $mimeType
	 * @param string $parsed
	 *
	 * @throws KnownFileMimeTypeException
	 */
	private function parseMimeTypePDF(string $mimeType, string &$parsed) {

		if ($mimeType === 'application/pdf') {
			$parsed = self::MIMETYPE_PDF;
			throw new KnownFileMimeTypeException();
		}
	}


	/**
	 * @param string $mimeType
	 * @param string $parsed
	 *
	 * @throws KnownFileMimeTypeException
	 */
	private function parseMimeTypeZip(string $mimeType, string &$parsed) {
		if ($mimeType === 'application/zip') {
			$parsed = self::MIMETYPE_ZIP;
			throw new KnownFileMimeTypeException();
		}
	}


	/**
	 * @param string $mimeType
	 * @param string $parsed
	 *
	 * @throws KnownFileMimeTypeException
	 */
	private function parseMimeTypeOffice(string $mimeType, string &$parsed) {

		$officeMimes = [
			'application/msword',
			'application/vnd.oasis.opendocument',
			'application/vnd.sun.xml',
			'application/vnd.openxmlformats-officedocument',
			'application/vnd.ms-word',
			'application/vnd.ms-powerpoint',
			'application/vnd.ms-excel'
		];

		foreach ($officeMimes as $mime) {
			if (strpos($mimeType, $mime) === 0) {
				$parsed = self::MIMETYPE_OFFICE;
				throw new KnownFileMimeTypeException();
			}
		}
	}


	/**
	 * @param FilesDocument $document
	 * @param File $file
	 *
	 * @throws NotPermittedException
	 */
	private function extractContentFromFileText(FilesDocument $document, File $file) {
		if ($this->parseMimeType($document->getMimeType(), $file->getExtension())
			!== self::MIMETYPE_TEXT) {
			return;
		}

		if (!$this->isSourceIndexable($document)) {
			return;
		}

		$document->setContent(
			base64_encode($file->getContent()), IIndexDocument::ENCODED_BASE64
		);
	}


	/**
	 * @param FilesDocument $document
	 * @param File $file
	 *
	 * @throws NotPermittedException
	 */
	private function extractContentFromFilePDF(FilesDocument $document, File $file) {
		if ($this->parseMimeType($document->getMimeType(), $file->getExtension())
			!== self::MIMETYPE_PDF) {
			return;
		}

		$this->configService->setDocumentIndexOption($document, ConfigService::FILES_PDF);
		if (!$this->isSourceIndexable($document)) {
			return;
		}

		if ($this->configService->getAppValue(ConfigService::FILES_PDF) !== '1') {
			$document->setContent('');

			return;
		}

		$document->setContent(
			base64_encode($file->getContent()), IIndexDocument::ENCODED_BASE64
		);
	}


	/**
	 * @param FilesDocument $document
	 * @param File $file
	 *
	 * @throws NotPermittedException
	 */
	private function extractContentFromFileZip(FilesDocument $document, File $file) {
		if ($this->parseMimeType($document->getMimeType(), $file->getExtension())
			!== self::MIMETYPE_ZIP) {
			return;
		}

		$this->configService->setDocumentIndexOption($document, ConfigService::FILES_ZIP);
		if (!$this->isSourceIndexable($document)) {
			return;
		}

		if ($this->configService->getAppValue(ConfigService::FILES_ZIP) !== '1') {
			$document->setContent('');

			return;
		}

		$document->setContent(
			base64_encode($file->getContent()), IIndexDocument::ENCODED_BASE64
		);
	}


	/**
	 * @param FilesDocument $document
	 * @param File $file
	 *
	 * @throws NotPermittedException
	 */
	private function extractContentFromFileOffice(FilesDocument $document, File $file) {
		if ($this->parseMimeType($document->getMimeType(), $file->getExtension())
			!== self::MIMETYPE_OFFICE) {
			return;
		}

		$this->configService->setDocumentIndexOption($document, ConfigService::FILES_OFFICE);
		if (!$this->isSourceIndexable($document)) {
			return;
		}

		if ($this->configService->getAppValue(ConfigService::FILES_OFFICE) !== '1') {
			$document->setContent('');

			return;
		}

		$document->setContent(
			base64_encode($file->getContent()), IIndexDocument::ENCODED_BASE64
		);
	}


	/**
	 * @param FilesDocument $document
	 *
	 * @return bool
	 */
	private function isSourceIndexable(FilesDocument $document): bool {
		$this->configService->setDocumentIndexOption($document, $document->getSource());
		if ($this->configService->getAppValue($document->getSource()) !== '1') {
			$document->setContent('');

			return false;
		}

		return true;
	}


	/**
	 * @param IIndex $index
	 */
	private function impersonateOwner(IIndex $index) {
		if ($index->getOwnerId() !== '') {
			return;
		}

		$this->groupFoldersService->impersonateOwner($index);
		$this->externalFilesService->impersonateOwner($index);
	}


	/**
	 * @param $action
	 * @param bool $force
	 *
	 * @throws Exception
	 */
	private function updateRunnerAction(string $action, bool $force = false) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->updateAction($action, $force);
	}


	/**
	 * @param array $data
	 */
	private function updateRunnerInfo($data) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->setInfoArray($data);
	}

	/**
	 * @param IIndexDocument $document
	 * @param Throwable $t
	 */
	private function manageContentErrorException(IIndexDocument $document, Throwable $t) {
		$document->getIndex()
				 ->addError(
					 'Error while getting file content', $t->getMessage(), IIndex::ERROR_SEV_3
				 );
		$this->updateNewIndexError(
			$document->getIndex(), 'Error while getting file content', $t->getMessage(),
			IIndex::ERROR_SEV_3
		);

		$trace = $t->getTrace();
		if (is_array($trace)) {
			$trace = json_encode($trace);
		}
		if (is_string($trace)) {
			$this->miscService->log($trace, 0);
		}
	}


	/**
	 * @param IIndex $index
	 */
	private function updateDirectoryContentIndex(IIndex $index) {
		if (!$index->isStatus(IIndex::INDEX_META)) {
			return;
		}

		try {
			$file = $this->getFileFromIndex($index);
			if ($file->getType() === File::TYPE_FOLDER) {
				/** @var Folder $file */
				$this->updateDirectoryMeta($file);
			}
		} catch (Exception $e) {
		}
	}


	/**
	 * @param Folder $node
	 */
	private function updateDirectoryMeta(Folder $node) {
		try {
			$files = $node->getDirectoryListing();
		} catch (NotFoundException $e) {
			return;
		}

		foreach ($files as $file) {
			try {
				$this->fullTextSearchManager->updateIndexStatus(
					'files', (string)$file->getId(), IIndex::INDEX_META
				);
			} catch (InvalidPathException $e) {
			} catch (NotFoundException $e) {
			}
		}
	}


	/**
	 * @param IIndex $index
	 * @param string $message
	 * @param string $exception
	 * @param int $sev
	 */
	private function updateNewIndexError(IIndex $index, string $message, string $exception, int $sev
	) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->newIndexError($index, $message, $exception, $sev);
	}


	/**
	 * @param Node $file
	 *
	 * @throws FileIsNotIndexableException
	 */
	private function isNodeIndexable(Node $file) {

		if ($file->getType() === File::TYPE_FOLDER) {
			/** @var Folder $file */
			if ($file->nodeExists('.noindex')) {
				throw new FileIsNotIndexableException();
			}
		}

		$parent = $file->getParent();
		$parentPath = $this->withoutBeginSlash($parent->getPath());
		$path = substr($parent->getPath(), 8 + strpos($parentPath, '/'));
		if (is_string($path)) {
			$this->isNodeIndexable($file->getParent());
		}
	}


	/**
	 * @param string $path
	 * @param string $userId
	 *
	 * @param bool $entrySlash
	 *
	 * @return string
	 */
	private function getPathFromRoot(string $path, string $userId, bool $entrySlash = false) {
		// TODO: better way to do this : we remove the '/userid/files/'
		// TODO: do we need userId, or can we crop the path like in isNodeIndexable()
		$path = substr($path, 8 + strlen($userId));
		if (!is_string($path)) {
			$path = '';
		}

		return (($entrySlash) ? '/' : '') . $path;
	}

}

