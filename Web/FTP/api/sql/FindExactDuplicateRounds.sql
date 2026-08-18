SELECT 
    participantEmail, seed, round, pickedUpCoin, finished, phase, date,
    remainingTime, totalRoundsFinished, day, thoughtBubbleTime, bufferDelay,
    coinPresentTime, playerChoiceTime, reactionTime, wentBackForCoin, coinIdentity,
    COUNT(*) AS occurrences
FROM r
GROUP BY
    participantEmail, seed, round, pickedUpCoin, finished, phase, date,
    remainingTime, totalRoundsFinished, day, thoughtBubbleTime, bufferDelay,
    coinPresentTime, playerChoiceTime, reactionTime, wentBackForCoin, coinIdentity
HAVING occurrences > 1
ORDER BY participantEmail, day, round;