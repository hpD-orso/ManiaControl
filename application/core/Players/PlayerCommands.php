<?php

namespace ManiaControl\Players;

use FML\Controls\Quads\Quad_Icons128x128_1;
use FML\Controls\Quads\Quad_Icons128x32_1;
use FML\Controls\Quads\Quad_Icons64x64_1;
use FML\Controls\Quads\Quad_UIConstruction_Buttons;
use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Commands\CommandListener;
use ManiaControl\ManiaControl;
use ManiaControl\Manialinks\ManialinkPageAnswerListener;

/**
 * Class offering various Admin Commands related to Players
 *
 * @author steeffeen & kremsy
 */
class PlayerCommands implements CommandListener, ManialinkPageAnswerListener {
	/**
	 * Constants
	 */
	const ACTION_BALANCE_TEAMS = 'PlayerCommands.BalanceTeams';
	const ACTION_OPEN_PLAYERLIST = 'PlayerCommands.OpenPlayerList';
	const ACTION_CANCEL_VOTE = 'PlayerCommands.CancelVote';
	
	/**
	 * Private Properties
	 */
	private $maniaControl = null;

	/**
	 * Create a new Player Commands Instance
	 *
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		
		// Register for admin commands
		$this->maniaControl->commandManager->registerCommandListener('balance', $this, 'command_TeamBalance', true);
		$this->maniaControl->commandManager->registerCommandListener('teambalance', $this, 'command_TeamBalance', true);
		$this->maniaControl->commandManager->registerCommandListener('autoteambalance', $this, 'command_TeamBalance', true);
		$this->maniaControl->commandManager->registerCommandListener('kick', $this, 'command_Kick', true);
		$this->maniaControl->commandManager->registerCommandListener('forcespec', $this, 'command_ForceSpectator', true);
		$this->maniaControl->commandManager->registerCommandListener('forcespectator', $this, 'command_ForceSpectator', true);
		$this->maniaControl->commandManager->registerCommandListener('forceplay', $this, 'command_ForcePlayer', true);
		$this->maniaControl->commandManager->registerCommandListener('forceplayer', $this, 'command_ForcePlayer', true);
		$this->maniaControl->commandManager->registerCommandListener('addbot', $this, 'command_AddFakePlayers', true);
		$this->maniaControl->commandManager->registerCommandListener('removebot', $this, 'command_RemoveFakePlayers', true);
		$this->maniaControl->commandManager->registerCommandListener('addbots', $this, 'command_AddFakePlayers', true);
		$this->maniaControl->commandManager->registerCommandListener('removebots', $this, 'command_RemoveFakePlayers', true);
		$this->maniaControl->commandManager->registerCommandListener('mute', $this, 'command_MutePlayer', true);
		$this->maniaControl->commandManager->registerCommandListener('unmute', $this, 'command_UnmutePlayer', true);
		// TODO: Move into another class
		$this->maniaControl->commandManager->registerCommandListener('cancel', $this, 'command_CancelVote', true);
		
		// Register for player chat commands
		$this->maniaControl->commandManager->registerCommandListener('player', $this, 'command_playerList');
		$this->maniaControl->commandManager->registerCommandListener('players', $this, 'command_playerList');
		
		// Action Balance Teams
		$this->maniaControl->manialinkManager->registerManialinkPageAnswerListener(self::ACTION_BALANCE_TEAMS, $this, 'command_TeamBalance');
		$itemQuad = new Quad_Icons128x32_1();
		$itemQuad->setSubStyle($itemQuad::SUBSTYLE_RT_Team);
		$itemQuad->setAction(self::ACTION_BALANCE_TEAMS);
		$this->maniaControl->actionsMenu->addMenuItem($itemQuad, false, 9, 'Balance Teams');
		
		// Action cancel Vote
		$this->maniaControl->manialinkManager->registerManialinkPageAnswerListener(self::ACTION_CANCEL_VOTE, $this, 'command_cancelVote');
		$itemQuad = new Quad_Icons64x64_1();
		$itemQuad->setSubStyle($itemQuad::SUBSTYLE_ArrowRed);
		$itemQuad->setAction(self::ACTION_CANCEL_VOTE);
		$this->maniaControl->actionsMenu->addMenuItem($itemQuad, false, 6, 'Cancel Vote');
		
		// Action Open Playerlist
		$this->maniaControl->manialinkManager->registerManialinkPageAnswerListener(self::ACTION_OPEN_PLAYERLIST, $this, 'command_playerList');
		$itemQuad = new Quad_UIConstruction_Buttons();
		$itemQuad->setSubStyle($itemQuad::SUBSTYLE_Author);
		$itemQuad->setAction(self::ACTION_OPEN_PLAYERLIST);
		$this->maniaControl->actionsMenu->addMenuItem($itemQuad, true, 15, 'Open Playerlist');
	}

	/**
	 * Handle //cancelvote command
	 *
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function command_CancelVote(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkRight($player, AuthenticationManager::AUTH_LEVEL_MODERATOR)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		$success = $this->maniaControl->client->query('CancelVote');
		if (!$success) {
			$this->maniaControl->chat->sendError('Error occurred: ' . $this->maniaControl->getClientErrorText(), $player->login);
			return;
		}
		$this->maniaControl->chat->sendInformation('$<' . $player->nickname . '$> canceled the Vote!');
	}

	/**
	 * Handle //teambalance command
	 *
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function command_TeamBalance(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkRight($player, AuthenticationManager::AUTH_LEVEL_MODERATOR)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		$success = $this->maniaControl->client->query('AutoTeamBalance');
		if (!$success) {
			$this->maniaControl->chat->sendError('Error occurred: ' . $this->maniaControl->getClientErrorText(), $player->login);
			return;
		}
		$this->maniaControl->chat->sendInformation('$<' . $player->nickname . '$> balanced Teams!');
	}

	/**
	 * Handle //kick command
	 *
	 * @param array $chat
	 * @param Player $player
	 */
	public function command_Kick(array $chat, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkRight($player, AuthenticationManager::AUTH_LEVEL_MODERATOR)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		$params = explode(' ', $chat[1][2], 3);
		if (count($params) <= 1) {
			$this->maniaControl->chat->sendUsageInfo("No Login given! Example: '//kick login'", $player->login);
			return;
		}
		$targetLogin = $params[1];
		$message = '';
		if (isset($params[2])) {
			$message = $params[2];
		}
		$this->maniaControl->playerManager->playerActions->kickPlayer($player->login, $targetLogin, $message);
	}

	/**
	 * Handle //warn Command
	 *
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function command_Warn(array $chatCallback, Player $player) {
		$params = explode(' ', $chat[1][2], 3);
		if (count($params) <= 1) {
			$this->maniaControl->chat->sendUsageInfo("No Login given! Example: '//kick login'", $player->login);
			return;
		}
		$targetLogin = $params[1];
		$this->maniaControl->playerManager->playerActions->warnPlayer($player->login, $targetLogin);
	}

	/**
	 * Handle //forcespec command
	 *
	 * @param array $chat
	 * @param Player $player
	 */
	public function command_ForceSpectator(array $chat, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkRight($player, AuthenticationManager::AUTH_LEVEL_MODERATOR)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		$params = explode(' ', $chat[1][2]);
		if (count($params) <= 1) {
			$this->maniaControl->chat->sendUsageInfo("No Login given! Example: '//forcespec login'", $player->login);
			return;
		}
		$targetLogin = $params[1];
		
		if (isset($params[2]) && is_numeric($params[2])) {
			$type = (int) $params[2];
			$this->maniaControl->playerManager->playerActions->forcePlayerToSpectator($player->login, $targetLogin, $type);
		}
		else {
			$this->maniaControl->playerManager->playerActions->forcePlayerToSpectator($player->login, $targetLogin);
		}
	}

	/**
	 * Handle //forceplay command
	 *
	 * @param array $chat
	 * @param Player $player
	 */
	public function command_ForcePlayer(array $chat, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkRight($player, AuthenticationManager::AUTH_LEVEL_MODERATOR)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		$params = explode(' ', $chat[1][2]);
		if (!isset($params[1])) {
			$this->maniaControl->chat->sendUsageInfo('Usage example: //forceplay login', $player->login);
			return;
		}
		$target = $params[1];
		$target = $this->maniaControl->playerManager->getPlayer($target);
		if (!$target) {
			$this->maniaControl->chat->sendError("Invalid player login.", $player->login);
			return;
		}
		$type = 2;
		if (isset($params[2]) && is_numeric($params[2])) {
			$type = intval($params[2]);
		}
		$success = $this->maniaControl->client->query('ForceSpectator', $target->login, 2);
		if (!$success) {
			$this->maniaControl->chat->sendError('Error occurred: ' . $this->maniaControl->getClientErrorText(), $player->login);
			return;
		}
		if ($type != 1) {
			$this->maniaControl->client->query('ForceSpectator', $target->login, 0);
		}
		$this->maniaControl->chat->sendInformation('$<' . $player->nickname . '$> forced $<' . $target->nickname . '$> to player!');
	}

	/**
	 * Handle //addfakeplayers command
	 *
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function command_AddFakePlayers(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkRight($player, AuthenticationManager::AUTH_LEVEL_ADMIN)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		$amount = 1;
		$messageParts = explode(' ', $chatCallback[1][2]);
		if (isset($messageParts[1]) && is_numeric($messageParts[1])) {
			$amount = intval($messageParts[1]);
		}
		$success = true;
		for ($i = 0; $i < $amount; $i++) {
			if (!$this->maniaControl->client->query('ConnectFakePlayer')) {
				$success = false;
			}
		}
		if (!$success) {
			$this->maniaControl->chat->sendError('Error occurred: ' . $this->maniaControl->getClientErrorText(), $player->login);
			return;
		}
		$this->maniaControl->chat->sendSuccess('Fake players connected!', $player->login);
	}

	/**
	 * Handle //removefakeplayers command
	 *
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function command_RemoveFakePlayers(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkRight($player, AuthenticationManager::AUTH_LEVEL_ADMIN)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		$success = $this->maniaControl->client->query('DisconnectFakePlayer', '*');
		if (!$success) {
			$this->maniaControl->chat->sendError('Error occurred: ' . $this->maniaControl->getClientErrorText(), $player->login);
			return;
		}
		$this->maniaControl->chat->sendSuccess('Fake players disconnected!', $player->login);
	}

	/**
	 * Handle //mute Command
	 *
	 * @param array $chatCallback
	 * @param Player $admin
	 */
	public function command_MutePlayer(array $chatCallback, Player $admin) {
		$commandParts = explode(' ', $chatCallback[1][2]);
		if (count($commandParts) <= 1) {
			$this->maniaControl->chat->sendUsageInfo("No login specified! Example: '//mute login'");
			return;
		}
		$targetLogin = $commandParts[1];
		$this->maniaControl->playerManager->playerActions->mutePlayer($admin->login, $targetLogin);
	}

	/**
	 * Handle //unmute Command
	 *
	 * @param array $chatCallback
	 * @param Player $admin
	 */
	public function command_UnmutePlayer(array $chatCallback, Player $admin) {
		$commandParts = explode(' ', $chatCallback[1][2]);
		if (count($commandParts) <= 1) {
			$this->maniaControl->chat->sendUsageInfo("No login specified! Example: '//unmute login'");
			return;
		}
		$targetLogin = $commandParts[1];
		$this->maniaControl->playerManager->playerActions->unMutePlayer($admin->login, $targetLogin);
	}

	/**
	 * Handle Player list command
	 *
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function command_playerList(array $chatCallback, Player $player) {
		$this->maniaControl->playerManager->playerList->addPlayerToShownList($player, PlayerList::SHOWN_MAIN_WINDOW);
		$this->maniaControl->playerManager->playerList->showPlayerList($player);
	}
}
