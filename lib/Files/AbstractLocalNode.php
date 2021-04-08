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

abstract class AbstractLocalNode extends AbstractNode implements NodeInterface
{
	/** @var string */
	protected $path;

	/** @var LocalFolder|null */
	protected $parentFolder;

	/** @var string */
	protected $rootPath;

	/** @var int|null */
	protected $permissions;

	/**
	 * AbstractLocalNode constructor.
	 *
	 * @param string      $path
	 * @param string|null $rootPath
	 *
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function __construct(string $path, string $rootPath = null)
	{
		parent::__construct();

		$this->path = $this->normalizePath($path);
		$this->rootPath = realpath($rootPath ?? \OC::$SERVERROOT);

		if (!file_exists($this->getLocalPath())) {
			throw new NotFoundException();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function rename(string $name): NodeInterface
	{
		$this->assertValidFileName($name);

		if (!$this->isDeletable()) {
			throw new NotPermittedException();
		}

		$parentNode = $this->getParentFolder();
		if ($parentNode->exists($name)) {
			throw new AlreadyExistsException();
		}
		if (!$parentNode->isCreatable()) {
			throw new NotPermittedException();
		}

		if (!rename($this->getLocalPath(), dirname($this->getLocalPath()) . '/' . $name)) {
			throw new GenericFileException();
		}

		$parentPath = dirname($this->path);
		$this->path = (($parentPath !== '/') ? $parentPath : '') . '/' . $name;

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function copy(FolderInterface $targetPath, string $name = null): NodeInterface
	{
		if (($targetPath instanceof LocalFolder) && $this->isFile()) {
			if ($name !== null) {
				$this->assertValidFileName($name);
			} else {
				$name = $this->getName();
			}

			if ($targetPath->exists($name)) {
				throw new AlreadyExistsException();
			}
			if (!$targetPath->isCreatable()) {
				throw new NotPermittedException();
			}

			if (!@copy($this->getLocalPath(), $targetPath->getLocalPath() . '/' . $name)) {
				throw new GenericFileException();
			}

			return new LocalFile($targetPath->getPath() . '/' . $name, $targetPath->getRootPath());
		} else {
			return parent::copy($targetPath, $name);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function move(FolderInterface $targetPath, string $name = null): NodeInterface
	{
		if (($targetPath instanceof LocalFolder) && $this->isFile()) {
			if ($name !== null) {
				$this->assertValidFileName($name);
			} else {
				$name = $this->getName();
			}

			if (!$this->isDeletable()) {
				throw new NotPermittedException();
			}

			if ($targetPath->exists($name)) {
				throw new AlreadyExistsException();
			}
			if (!$targetPath->isCreatable()) {
				throw new NotPermittedException();
			}

			if (!@rename($this->getLocalPath(), $targetPath->getLocalPath() . '/' . $name)) {
				throw new GenericFileException();
			}

			return new LocalFile($targetPath->getPath() . '/' . $name, $targetPath->getRootPath());
		} else {
			return parent::move($targetPath, $name);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function getPath(): string
	{
		return $this->path;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getLocalPath(): string
	{
		return $this->rootPath . (($this->path !== '/') ? $this->path : '');
	}

	/**
	 * {@inheritDoc}
	 */
	public function getName(): string
	{
		return basename($this->path);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getParentPath(): string
	{
		if ($this->path === '/') {
			throw new InvalidPathException();
		}

		return dirname($this->path);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getParentFolder(): FolderInterface
	{
		if ($this->parentFolder === null) {
			$this->parentFolder = new LocalFolder($this->getParentPath(), $this->rootPath);
		}

		return $this->parentFolder;
	}

	/**
	 * @return string
	 */
	public function getRootPath(): string
	{
		return $this->rootPath;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isFile(): bool
	{
		return is_file($this->getLocalPath());
	}

	/**
	 * {@inheritDoc}
	 */
	public function isFolder(): bool
	{
		return is_dir($this->getLocalPath());
	}

	/**
	 * {@inheritDoc}
	 */
	public function isLocal(): bool
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getPermissions(): int
	{
		if ($this->permissions === null) {
			$this->permissions = 0;

			try {
				$localPath = $this->getLocalPath();
			} catch (\Exception $e) {
				// can't read a node's permissions without its local path
				return $this->permissions;
			}

			if (is_readable($localPath)) {
				$this->permissions |= Constants::PERMISSION_READ;
			}

			if (is_writable($localPath)) {
				$this->permissions |= Constants::PERMISSION_UPDATE;

				if ($this->isFolder() && is_executable($localPath)) {
					$this->permissions |= Constants::PERMISSION_CREATE;
				}
			}

			try {
				if (is_writable($this->getParentFolder()->getLocalPath())) {
					$this->permissions |= Constants::PERMISSION_DELETE;
				}
			} catch (\Exception $e) {
				// this is either the root folder or we can't read the parent folder's local path
				// in neither case we can delete this node
			}
		}

		return $this->permissions;
	}
}
