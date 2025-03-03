<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
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
namespace OCA\PreviewGenerator\Command;

use OCA\PreviewGenerator\SizeHelper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Encryption\IManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PreGenerate extends Command {

	/** @var string */
	protected $appName;

	/** @var IUserManager */
	protected $userManager;

	/** @var IRootFolder */
	protected $rootFolder;

	/** @var IPreview */
	protected $previewGenerator;

	/** @var IConfig */
	protected $config;

	/** @var IDBConnection */
	protected $connection;

	/** @var OutputInterface */
	protected $output;

	/** @var int[][] */
	protected $sizes;

	/** @var IManager */
	protected $encryptionManager;

	/** @var ITimeFactory */
	protected $time;

	/**
	 * @param string $appName
	 * @param IRootFolder $rootFolder
	 * @param IUserManager $userManager
	 * @param IPreview $previewGenerator
	 * @param IConfig $config
	 * @param IDBConnection $connection
	 * @param IManager $encryptionManager
	 * @param ITimeFactory $time
	 */
	public function __construct($appName,
						 IRootFolder $rootFolder,
						 IUserManager $userManager,
						 IPreview $previewGenerator,
						 IConfig $config,
						 IDBConnection $connection,
						 IManager $encryptionManager,
						 ITimeFactory $time) {
		parent::__construct();

		$this->appName = $appName;
		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		$this->previewGenerator = $previewGenerator;
		$this->config = $config;
		$this->connection = $connection;
		$this->encryptionManager = $encryptionManager;
		$this->time = $time;
	}

	protected function configure() {
		$this
			->setName('preview:pre-generate')
			->setDescription('Pre generate previews');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($this->encryptionManager->isEnabled()) {
			$output->writeln('Encryption is enabled. Aborted.');
			return 1;
		}

		if ($this->checkAlreadyRunning()) {
			$output->writeln('Command is already running.');
			return 2;
		}

		$this->setPID();

		// Set timestamp output
		$formatter = new TimestampFormatter($this->config, $output->getFormatter());
		$output->setFormatter($formatter);
		$this->output = $output;

		$this->sizes = SizeHelper::calculateSizes($this->config);
		$this->startProcessing();

		$this->clearPID();

		return 0;
	}

	private function startProcessing() {
		while (true) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('*')
				->from('preview_generation')
				->orderBy('id')
				->setMaxResults(25);		# 1000 -> 25 and run every 5 mintues on cron so that server shutdowns don't kill pregeneration.
			$cursor = $qb->execute();
			$rows = $cursor->fetchAll();
			$cursor->closeCursor();

			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				/*
				 * First delete the row so that if preview generation fails for some reason
				 * the next run can just continue
				 */
				$qb = $this->connection->getQueryBuilder();
				$qb->delete('preview_generation')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])));
				$qb->execute();

				$this->processRow($row);
			}
		}
	}

	private function processRow($row) {
		//Get user
		$user = $this->userManager->get($row['uid']);

		if ($user === null) {
			return;
		}

		\OC_Util::tearDownFS();
		\OC_Util::setupFS($row['uid']);

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$userRoot = $userFolder->getParent();
		} catch (NotFoundException $e) {
			return;
		}

		//Get node
		$nodes = $userRoot->getById($row['file_id']);

		if ($nodes === []) {
			return;
		}

		$node = $nodes[0];
		if ($node instanceof File) {
			$this->processFile($node);
		}
	}

	private function processFile(File $file) {
		if ($this->previewGenerator->isMimeSupported($file->getMimeType())) {
			if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_VERBOSE) {
				$this->output->writeln('Generating previews for ' . $file->getPath());
			}

			try {
				$specifications = array_merge(
					array_map(function ($squareSize) {
						return ['width' => $squareSize, 'height' => $squareSize, 'crop' => true];
					}, $this->sizes['square']),
					array_map(function ($heightSize) {
						return ['width' => -1, 'height' => $heightSize, 'crop' => false];
					}, $this->sizes['height']),
					array_map(function ($widthSize) {
						return ['width' => $widthSize, 'height' => -1, 'crop' => false];
					}, $this->sizes['width'])
				);
				$this->previewGenerator->generatePreviews($file, $specifications);
				usleep(8000);			# ensures server won't hang for more than 2 seconds (set cron job to occur every 5 minutes)
			} catch (NotFoundException $e) {
				// Maybe log that previews could not be generated?
			} catch (\InvalidArgumentException $e) {
				$error = $e->getMessage();
				$this->output->writeln("<error>${error}</error>");
			}
		}
	}

	private function setPID() {
		$this->config->setAppValue($this->appName, 'pid', posix_getpid());
	}

	private function clearPID() {
		$this->config->deleteAppValue($this->appName, 'pid');
	}

	private function getPID() {
		return (int)$this->config->getAppValue($this->appName, 'pid', -1);
	}

	private function checkAlreadyRunning() {
		$pid = $this->getPID();

		// No PID set so just continue
		if ($pid === -1) {
			return false;
		}

		// Get get the gid of non running processes so continue
		if (posix_getpgid($pid) === false) {
			return false;
		}

		// Seems there is already a running process generating previews
		return true;
	}
}
