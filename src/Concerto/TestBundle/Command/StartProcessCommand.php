<?php

namespace Concerto\TestBundle\Command;

use Concerto\TestBundle\Service\RRunnerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class StartProcessCommand extends Command {

    const SOURCE_PANEL_NODE = 0;
    const SOURCE_PROCESS = 1;
    const SOURCE_TEST_NODE = 2;
    const RESPONSE_VIEW_TEMPLATE = 0;
    const RESPONSE_FINISHED = 1;
    const RESPONSE_SUBMIT = 2;
    const RESPONSE_SERIALIZE = 3;
    const RESPONSE_SERIALIZATION_FINISHED = 4;
    const RESPONSE_VIEW_FINAL_TEMPLATE = 5;
    const RESPONSE_VIEW_RESUME = 6;
    const RESPONSE_RESULTS = 7;
    const RESPONSE_AUTHENTICATION_FAILED = 8;
    const RESPONSE_STARTING = 9;
    const RESPONSE_KEEPALIVE_CHECKIN = 10;
    const RESPONSE_UNRESUMABLE = 11;
    const RESPONSE_ERROR = -1;

    private $panelNode;
    private $output;
    private $lastProcessTime;   //for max execution timeout
    private $lastClientTime;    //for idle timeout
    private $lastKeepAliveTime; //for keep alive timeout
    private $maxExecTime;
    private $maxIdleTime;
    private $keepAliveIntervalTime;
    private $keepAliveToleranceTime;
    private $isSerializing;
    private $isWaitingForProcess;
    private $isDebug;
    private $currentTotalDebugData = "";
    private $logPath;
    private $rLogPath;
    private $rEnviron;

    protected function configure() {
        $this->isSerializing = false;
        $this->isWaitingForProcess = false;
        $this->isDebug = false;
        $this->currentTotalDebugData = "";
        $this->setName("concerto:r:start")->setDescription("Starts new R session.");
        $this->addArgument("rscript_exec_path", InputArgument::REQUIRED, "Rscript executable file path");
        $this->addArgument("ini_path", InputArgument::REQUIRED, "initialization file path");
        $this->addArgument("test_node", InputArgument::REQUIRED, "test node json serialized data");
        $this->addArgument("panel_node", InputArgument::REQUIRED, "panel node json serialized data");
        $this->addArgument("test_session_id", InputArgument::REQUIRED, "test session id");
        $this->addArgument("panel_node_connection", InputArgument::REQUIRED, "panel node connection json serialized data");
        $this->addArgument("client", InputArgument::REQUIRED, "client json serialized data");
        $this->addArgument("working_directory", InputArgument::REQUIRED, "session working directory");
        $this->addArgument("public_directory", InputArgument::REQUIRED, "public directory");
        $this->addArgument("media_url", InputArgument::REQUIRED, "media URL");
        $this->addArgument("log_path", InputArgument::REQUIRED, "log path");
        $this->addArgument("debug", InputArgument::REQUIRED, "debug test execution");
        $this->addArgument("max_idle_time", InputArgument::REQUIRED, "max time without any R code interpretation");
        $this->addArgument("max_exec_time", InputArgument::REQUIRED, "max time R code can be interpreted");
        $this->addArgument("keep_alive_interval_time", InputArgument::REQUIRED, "keep-alive interval time");
        $this->addArgument("keep_alive_tolerance_time", InputArgument::REQUIRED, "keep-alive tolerance time");
        $this->addArgument("submit", InputArgument::OPTIONAL, "submitted variables");

        $this->addOption("r_environ", "renv", InputOption::VALUE_OPTIONAL, "R Renviron file path", null);
    }

    private function createListenerSocket($ip) {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__, true);
        if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            return false;
        }
        if (socket_bind($sock, "0.0.0.0") === false) {
            return false;
        }
        if (socket_listen($sock, SOMAXCONN) === false) {
            return false;
        }
        socket_set_nonblock($sock);
        return $sock;
    }

    private function createPanelNodeResponseSocket() {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__, true);
        if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            return false;
        }
        if (socket_connect($sock, gethostbyname($this->panelNode->sock_host), $this->panelNode->port) === false) {
            $this->output->write("socket_connect failed: " . socket_strerror(socket_last_error()), true);
            return false;
        }
        return $sock;
    }

    private function startListener($server_sock, $submitter_sock) {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__, true);
        $this->lastClientTime = time();
        $this->lastKeepAliveTime = time();
        $this->lastProcessTime = time();
        do {
            $this->checkIdleTimeout($submitter_sock) || $this->checkKeepAliveTimeout($submitter_sock);
            if ($this->checkExecutionTimeout()) {
                break;
            }
            if (($client_sock = @socket_accept($server_sock)) === false) {
                continue;
            }
            $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : socket accepted", true);
            if (false === ($buf = socket_read($client_sock, 8388608, PHP_NORMAL_READ))) {
                continue;
            }
            if (!$msg = trim($buf)) {
                continue;
            }
            $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : read (" . $msg . ")", true);
            if ($this->interpretMessage($submitter_sock, $msg)) {
                break;
            }
        } while (usleep(100 * 1000) || true);
        $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : listener ended", true);
    }

    private function checkExecutionTimeout() {
        if (time() - $this->lastProcessTime > $this->maxExecTime && $this->isWaitingForProcess) {
            $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : execution timeout reached", true);
            $this->respondToPanelNode(json_encode(array(
                "source" => self::SOURCE_TEST_NODE,
                "code" => self::RESPONSE_ERROR
            )));
            return true;
        } else {
            return false;
        }
    }

    private function checkIdleTimeout($submitter_sock) {
        if (time() - $this->lastClientTime > $this->maxIdleTime && !$this->isSerializing) {
            $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : idle timeout reached", true);
            $this->isSerializing = true;
            $this->respondToProcess($submitter_sock, json_encode(array(
                "source" => self::SOURCE_TEST_NODE,
                "code" => self::RESPONSE_SERIALIZE
            )));
            return true;
        } else {
            return false;
        }
    }

    private function checkKeepAliveTimeout($submitter_sock) {
        if ($this->keepAliveIntervalTime > 0 && time() - $this->lastKeepAliveTime > $this->keepAliveIntervalTime + $this->keepAliveToleranceTime && !$this->isSerializing) {
            $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : keep alive timeout reached", true);
            $this->isSerializing = true;
            $this->respondToProcess($submitter_sock, json_encode(array(
                "source" => self::SOURCE_TEST_NODE,
                "code" => self::RESPONSE_SERIALIZE
            )));
            return true;
        } else {
            return false;
        }
    }

    private function interpretMessage($submitter_sock, $message) {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " - $message", true);
        $msg = json_decode($message);
        switch ($msg->source) {
            case self::SOURCE_PROCESS: {
                    return $this->interpretProcessMessage($message);
                }
            case self::SOURCE_PANEL_NODE: {
                    return $this->interpretPanelNodeMessage($submitter_sock, $message);
                }
        }
    }

    private function interpretPanelNodeMessage($submitter_sock, $message) {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " - $message", true);
        $msg = json_decode($message);
        switch ($msg->code) {
            case self::RESPONSE_SUBMIT: {
                    $this->isWaitingForProcess = true;
                    $this->panelNode = $msg->panelNode;
                    $this->lastClientTime = time();
                    $this->lastKeepAliveTime = time();
                    $this->respondToProcess($submitter_sock, $message);
                    return false;
                }
            case self::RESPONSE_KEEPALIVE_CHECKIN: {
                    $this->lastKeepAliveTime = time();
                    return false;
                }
        }
    }

    private function interpretProcessMessage($message) {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " - $message", true);
        $this->isWaitingForProcess = false;
        $this->lastProcessTime = time();
        $msg = json_decode($message, true);
        switch ($msg["code"]) {
            case self::RESPONSE_VIEW_TEMPLATE: {
                    $this->respondToPanelNode($message);
                    return false;
                }
            case self::RESPONSE_UNRESUMABLE:
            case self::RESPONSE_ERROR:
            case self::RESPONSE_FINISHED:
            case self::RESPONSE_VIEW_FINAL_TEMPLATE: {
                    $this->respondToPanelNode($message);
                    return true;
                }
            case self::RESPONSE_SERIALIZATION_FINISHED: {
                    return true;
                }
        }
    }

    private function respondToProcess($submitter_sock, $response) {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " - $response", true);
        $this->lastProcessTime = time();
        do {
            if (($client_sock = socket_accept($submitter_sock)) === false) {
                continue;
            }
            $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : socket accepted", true);
            socket_write($client_sock, $response . "\n");
            break;
        } while (true);
        $this->output->write(__CLASS__ . ":" . __FUNCTION__ . " : submitter ended", true);
    }

    private function respondToPanelNode($response) {
        $this->output->write(__CLASS__ . ":" . __FUNCTION__, true);
        if ($this->isDebug) {
            $response = $this->appendDebugDataToResponse($response);
        }
        $this->output->write($response, true);
        $resp_sock = $this->createPanelNodeResponseSocket();
        socket_write($resp_sock, $response . "\n");
        socket_close($resp_sock);
    }

    private function appendDebugDataToResponse($response) {
        if (file_exists($this->rLogPath)) {
            $new_data = file_get_contents($this->rLogPath, false, null, strlen($this->currentTotalDebugData));
            $this->currentTotalDebugData .= $new_data;
            $decoded_response = json_decode($response, true);
            $decoded_response["debug"] = mb_convert_encoding($new_data, "UTF-8");
            $response = json_encode($decoded_response);
        }
        return $response;
    }

    //TODO proper OS detection
    private function getOS() {
        if (strpos(strtolower(PHP_OS), "win") !== false) {
            return RRunnerService::OS_WIN;
        } else {
            return RRunnerService::OS_LINUX;
        }
    }

    private function getCommand($rscript_exec, $ini_path, $panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values) {
        switch ($this->getOS()) {
            case RRunnerService::OS_LINUX:
                return $rscript_exec . " --no-save --no-restore --quiet "
                        . "'$ini_path' "
                        . "'$panel_node_connection' "
                        . "'$test_node' "
                        . "'$submitter' "
                        . "'$client' "
                        . "$test_session_id "
                        . "'$wd' "
                        . "'$pd' "
                        . "'$murl' "
                        . "'$values' "
                        . ">> "
                        . "'" . $this->logPath . "' "
                        . "> "
                        . "'" . $this->rLogPath . "' "
                        . "2>&1";
            default:
                $client = str_replace("(", "^(", $client);
                $client = str_replace(")", "^)", $client);
                $cmd = "\"$rscript_exec\" --no-save --no-restore --quiet "
                        . "\"" . addcslashes($ini_path, '\\') . "\" "
                        . "\"" . addcslashes($panel_node_connection, '"\\') . "\" "
                        . "\"" . addcslashes($test_node, '"\\') . "\" "
                        . "\"" . addcslashes($submitter, '"\\') . "\" "
                        . "\"" . addcslashes($client, '"\\') . "\" "
                        . "$test_session_id "
                        . "\"" . addcslashes($wd, '\\') . "\" "
                        . "\"" . addcslashes($pd, '\\') . "\" "
                        . "$murl "
                        . "\"" . addcslashes($values, '"\\') . "\" "
                        . ">> "
                        . "\"" . addcslashes($this->logPath, '\\') . "\" "
                        . "> "
                        . "\"" . addcslashes($this->rLogPath, '\\') . "\" "
                        . "2>&1";
                return $cmd;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        if ($this->getOS() == RRunnerService::OS_LINUX) {
            if (posix_getpid() != posix_getsid(getmypid())) {
                posix_setsid();
            }
        }

        $this->output = $output;
        $this->output->write(__CLASS__ . ":" . __FUNCTION__, true);
        $rscript_exec = $input->getArgument("rscript_exec_path");
        $panel_node = $input->getArgument("panel_node");
        $this->panelNode = json_decode($panel_node);
        $panel_node_connection = $input->getArgument("panel_node_connection");
        $client = $input->getArgument("client");
        $ini_path = $input->getArgument("ini_path");
        $test_session_id = $input->getArgument("test_session_id");
        $wd = $input->getArgument("working_directory");
        $pd = $input->getArgument("public_directory");
        $murl = $input->getArgument("media_url");
        $this->logPath = $input->getArgument("log_path");
        $this->rLogPath = $this->logPath . ".r";
        $values = $input->getArgument("submit");
        $this->isDebug = $input->getArgument("debug") == 1;
        if (!$values) {
            $values = "";
        }
        $this->maxExecTime = $input->getArgument("max_exec_time");
        $this->maxIdleTime = $input->getArgument("max_idle_time");
        $this->keepAliveIntervalTime = $input->getArgument("keep_alive_interval_time");
        $this->keepAliveToleranceTime = $input->getArgument("keep_alive_tolerance_time");
        $this->rEnviron = $input->getOption("r_environ");

        $test_node = $input->getArgument("test_node");
        $decoded_test_node = json_decode($test_node, true);

        $test_node_sock = $this->createListenerSocket(gethostbyname($decoded_test_node["sock_host"]));
        socket_getsockname($test_node_sock, $test_node_ip, $test_node_port);
        $decoded_test_node = json_decode($test_node, true);
        $decoded_test_node["port"] = $test_node_port;
        $test_node = json_encode($decoded_test_node);

        $submitter_sock = $this->createListenerSocket($test_node_ip);
        socket_getsockname($submitter_sock, $submitter_ip, $submitter_port);
        $submitter = json_encode(array("host" => $submitter_ip, "port" => $submitter_port));

        $cmd = $this->getCommand($rscript_exec, $ini_path, $panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values);
        $this->output->write($cmd, true);

        $process = new Process($cmd);
        if ($this->rEnviron != null) {
            $env = array();
            $env["R_ENVIRON"] = $this->rEnviron;
            $process->setEnv($env);
        }
        $process->start();
        $this->isWaitingForProcess = true;
        $this->startListener($test_node_sock, $submitter_sock);
        socket_close($submitter_sock);
        socket_close($test_node_sock);
    }

}
