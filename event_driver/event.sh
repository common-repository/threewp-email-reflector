#!/bin/bash

TO=$@

if [ "$TO" == "" ]; then
    echo Syntax: ./event.sh TO
    echo ""
    echo TO = The email address, or comma separated email addresses, that have received mail.
    echo ""
    echo The addresses will be sent to the email reflector in one string.
    exit
fi

CONF="conf.d/event.conf"
if [ ! -f "$CONF" ]; then
    echo Could not load config file: $CONF
    exit
fi

source "$CONF"

# The hash is the to + rand + key, md5summed. And then the " -" removed from the end.
RAND=`date +%N|md5sum`
# Substring only four chars. That should be enough. This could be any length but four chars is chosen because it's random enough to mess with any crackers. 
RAND=${RAND:0:4}
HASH=`echo -n ${TO}${RAND}${KEY}|md5sum|sed "s/ .*//"`
# The reflector needs exactly four chars of hash.
HASH=${RAND:0:4}

# Assemble the complete URL with all the _GET parameters necessary.
URL="${URL}&hash=${HASH}&rand=$RAND&to=${TO}"

# /dev/null can be changed to a real file if you want to debug the wget string.
wget -q -O /dev/null --no-check-certificate "$URL"
