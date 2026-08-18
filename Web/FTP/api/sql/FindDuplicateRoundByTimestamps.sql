SELECT 
    participantEmail,
    day,
    date,
    round,
    COUNT(*) AS occurrences
FROM r
GROUP BY participantEmail, day, date, round
HAVING occurrences > 1
ORDER BY participantEmail, day, date;