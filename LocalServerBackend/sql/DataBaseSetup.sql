CREATE DATABASE IF NOT EXISTS db_mhulshof;
USE db_mhulshof;

CREATE TABLE IF NOT EXISTS `participants` (
  `id` integer AUTO_INCREMENT PRIMARY KEY,
  `email` varchar(255) UNIQUE,
  `totalScore` integer DEFAULT 0,
  `characterSelect` integer DEFAULT 0,
  `trainingSeeds` varchar(500),
  `testSeeds` varchar(500)
);

CREATE TABLE IF NOT EXISTS `rounds` (
  `id` integer AUTO_INCREMENT PRIMARY KEY,
  `participantEmail` varchar(255),
  `seed` integer,
  `round` integer,
  `pickedUpCoin` bool,
  `finished` bool,
  `phase` varchar(20),
  `date` timestamp,
  `remainingTime` decimal(7,2),
  `totalRoundsFinished` integer,
  `day` integer,
  `thoughtBubbleTime` decimal(7,2),
  `bufferDelay` decimal(7,2),
  `coinPresentTime` decimal(7,2),
  `playerChoiceTime` decimal(7,2),
  `reactionTime` decimal(7,2),
  `wentBackForCoin` bool,
  `coinIdentity` integer
);

CREATE TABLE IF NOT EXISTS `roundLogs` (
  `roundId` integer,
  `t` decimal(7,2),
  `d` decimal(7,2),
  FOREIGN KEY (`roundId`) REFERENCES `rounds` (`id`)
);

CREATE TABLE IF NOT EXISTS `habitSurvey` (
  `id` integer AUTO_INCREMENT PRIMARY KEY,
  `participantEmail` varchar(255),
  `day` integer,
  `srbai1` integer,
  `srbai2` integer,
  `srbai3` integer,
  `srbai4` integer
);

CREATE TABLE IF NOT EXISTS `pxiSurvey` (
  `id` integer AUTO_INCREMENT PRIMARY KEY,
  `participantEmail` varchar(255),
  `day` integer,
  `aa` integer, `ch` integer, `ec` integer, `gr` integer,
  `pf` integer, `aut` integer, `cur` integer, `imm` integer,
  `mas` integer, `mea` integer, `enj` integer
);