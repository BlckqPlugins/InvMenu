<?php

/*
 *  ___            __  __
 * |_ _|_ ____   _|  \/  | ___ _ __  _   _
 *  | || '_ \ \ / / |\/| |/ _ \ '_ \| | | |
 *  | || | | \ V /| |  | |  __/ | | | |_| |
 * |___|_| |_|\_/ |_|  |_|\___|_| |_|\__,_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Muqsit
 * @link http://github.com/Muqsit
 *
*/

declare(strict_types=1);

namespace muqsit\invmenu;

use Closure;
use InvalidStateException;
use muqsit\invmenu\inventory\InvMenuInventory;
use muqsit\invmenu\metadata\MenuMetadata;
use muqsit\invmenu\session\PlayerManager;
use muqsit\invmenu\transaction\DeterministicInvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\item\Item;
use pocketmine\player\Player;

class InvMenu implements MenuIds{

	public static function create(string $identifier) : InvMenu{
		return new InvMenu(InvMenuHandler::getMenuType($identifier));
	}

    public function readonly(bool $value = true) : self{
        if(!InvMenuHandler::isRegistered()){
            throw new InvalidStateException("Tried altering readonly state before registration");
        }
        $this->readonly = $value;
        return $this;
    }

	/** @var MenuMetadata */
	protected $type;

	/** @var string|null */
	protected $name;

	/** @var Closure|null */
	protected $listener;

	/** @var Closure|null */
	protected $inventory_close_listener;

	/** @var InvMenuInventory */
	protected $inventory;

    /** @var bool */
    protected $readonly = false;

	public function __construct(MenuMetadata $type){
		if(!InvMenuHandler::isRegistered()){
			throw new InvalidStateException("Tried creating menu before calling " . InvMenuHandler::class . "::register()");
		}
		$this->type = $type;
		$this->inventory = $this->type->createInventory();
	}

	public function getType() : MenuMetadata{
		return $this->type;
	}

	public function getName() : ?string{
		return $this->name;
	}

	public function setName(?string $name) : self{
		$this->name = $name;
		return $this;
	}

    public function setListener(?callable $listener) : self{
        if(!InvMenuHandler::isRegistered()){
            throw new InvalidStateException("Tried setting listener before registration");
        }
        $this->listener = $listener;
        return $this;
    }

    public function setInventoryCloseListener(?callable $listener) : self{
        if(!InvMenuHandler::isRegistered()){
            throw new InvalidStateException("Tried setting inventory close listener before registration");
        }
        $this->inventory_close_listener = $listener;
        return $this;
    }

	/**
	 * @param Player $player
	 * @param string|null $name
	 * @param Closure|null $callback
	 *
	 * @phpstan-param Closure(bool) : void $callback
	 */
    final public function send(Player $player, ?string $name = null, ?Closure $callback = null) : void{
        $player->removeCurrentWindow();

        $session = PlayerManager::getNonNullable($player);
        $network = $session->getNetwork();
        $network->dropPending();
        $network->wait(function(bool $success) use($player, $session, $name, $callback) : void{
            if($success){
                $extradata = $session->getMenuExtradata();
                $extradata->setName($name ?? $this->getName());
                $extradata->setPosition($this->type->calculateGraphicPosition($player));
                $this->type->sendGraphic($player, $extradata);
                $session->setCurrentMenu($this, $callback);
                var_dump("Send");
            }elseif($callback !== null){
                $callback(false);
            }
        });
    }

	public function getInventory() : InvMenuInventory{
		return $this->inventory;
	}

    /**
     * @internal use InvMenu::send() instead.
     *
     * @param Player $player
     * @return bool
     */
    public function sendInventory(Player $player) : bool{
        return $player->setCurrentWindow($this->getInventory());
    }

    public function handleInventoryTransaction(Player $player, Item $in, Item $out, SlotChangeAction $action, InventoryTransaction $transaction) : bool{
        if($this->readonly){
            if($this->listener !== null){
                ($this->listener)($player, $in, $out, $action, $transaction);
            }
            return false;
        }

        return $this->listener === null || ($this->listener)($player, $in, $out, $action);
    }

	public function onClose(Player $player) : void{
		if($this->inventory_close_listener !== null){
			($this->inventory_close_listener)($player, $this->getInventory());
		}

		PlayerManager::getNonNullable($player)->removeCurrentMenu();
	}
}
