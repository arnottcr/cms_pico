<?php
/**
 * CMS Pico - Create websites using Pico CMS for Nextcloud.
 *
 * @copyright Copyright (c) 2019, Daniel Rudolf (<picocms.org@daniel-rudolf.de>)
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
 */

declare(strict_types=1);

namespace OCA\CMSPico\Files;

use OCP\Constants;
use OCP\Files\AlreadyExistsException;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

class LocalFolder extends AbstractLocalNode implements FolderInterface
{
	use FolderTrait;

	/** @var LocalFolder|null */
	protected $rootFolder;

	/**
	 * LocalFolder constructor.
	 *
	 * @param string      $path
	 * @param string|null $rootPath
	 *
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function __construct(string $path, string $rootPath = null)
	{
		parent::__construct($path, $rootPath);

		if (!is_dir($this->getLocalPath())) {
			throw new InvalidPathException();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete(): void
	{
		if (!$this->isDeletable()) {
			throw new NotPermittedException();
		}

		foreach ($this as $node) {
			$node->delete();
		}

		if (!@rmdir($this->getLocalPath())) {
			$files = @scandir($this->getLocalPath());
			if (($files === false) || ($files === [ '.', '..' ])) {
				throw new GenericFileException();
			} else {
				throw new NotPermittedException();
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function listing(): array
	{
		return iterator_to_array($this->getGenerator());
	}

	/**
	 * {@inheritDoc}
	 */
	protected function getGenerator(): \Generator
	{
		if (!$this->isReadable()) {
			throw new NotPermittedException();
		}

		$files = @scandir($this->getLocalPath());
		if ($files === false) {
			throw new GenericFileException();
		}

		foreach ($files as $file) {
			if (($file === '.') || ($file === '..')) {
				continue;
			}

			$path = (($this->path !== '/') ? $this->path . '/' : '/') . $file;
			$node = $this->createNode($path);
			if ($node !== null) {
				yield $node;
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function exists(string $path): bool
	{
		$path = $this->normalizePath($this->path . '/' . $path);

		return file_exists($this->rootPath . $path);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $path): NodeInterface
	{
		if (!$this->exists($path)) {
			throw new NotFoundException();
		}

		$path = $this->normalizePath($this->path . '/' . $path);

		$node = $this->createNode($path);
		if ($node !== null) {
			return $node;
		}

		throw new NotFoundException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function newFolder(string $path): FolderInterface
	{
		if ($this->exists($path)) {
			throw new AlreadyExistsException();
		}

		$path = $this->normalizePath($this->path . '/' . $path);
		$parentFolder = $this->newFolderRecursive(dirname($path));

		if (!$parentFolder->isCreatable()) {
			throw new NotPermittedException();
		}

		if (!@mkdir($this->rootPath . '/' . $path)) {
			throw new GenericFileException();
		}

		return new LocalFolder($path, $this->rootPath);
	}

	/**
	 * {@inheritDoc}
	 */
	public function newFile(string $path): FileInterface
	{
		if ($this->exists($path)) {
			throw new AlreadyExistsException();
		}

		$path = $this->normalizePath($this->path . '/' . $path);
		$parentFolder = $this->newFolderRecursive(dirname($path));

		if (!$parentFolder->isCreatable()) {
			throw new NotPermittedException();
		}

		if (!@touch($this->rootPath . '/' . $path)) {
			throw new GenericFileException();
		}

		return new LocalFile($path, $this->rootPath);
	}

	/**
	 * {@inheritDoc}
	 */
	public function fakeRoot(): FolderInterface
	{
		return new LocalFolder('/', $this->getLocalPath());
	}

	/**
	 * {@inheritDoc}
	 */
	public function sync(bool $recursive = self::SYNC_RECURSIVE): void
	{
		// nothing to do
	}

	/**
	 * {@inheritDoc}
	 */
	public function isCreatable(): bool
	{
		return ($this->getPermissions() & Constants::PERMISSION_CREATE) === Constants::PERMISSION_CREATE;
	}

	/**
	 * @param string $path
	 *
	 * @return LocalFolder|LocalFile|null
	 */
	protected function createNode(string $path): ?AbstractLocalNode
	{
		try {
			if ($path === '/') {
				return new LocalFolder('/', $this->rootPath);
			} elseif (is_file($this->rootPath . '/' . $path)) {
				return new LocalFile($path, $this->rootPath);
			} elseif (is_dir($this->rootPath . '/' . $path)) {
				return new LocalFolder($path, $this->rootPath);
			}

			return null;
		} catch (NotFoundException $e) {
			return null;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function getRootFolder(): self
	{
		if ($this->rootFolder === null) {
			$this->rootFolder = new LocalFolder('/', $this->rootPath);
		}

		return $this->rootFolder;
	}
}
