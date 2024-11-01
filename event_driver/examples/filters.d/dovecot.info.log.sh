#!/bin/bash

# Filters Dovecot's info.log file, searching for addresses that the e-mail reflector handles.

# Version 2012-12-04

# Note that this script is run from the root, not from filters.d
CONF="conf.d/dovecot.info.log.conf"
if [ ! -f "$CONF" ];then
    echo The config file $CONF can not be read.
    exit
fi
source $CONF

STRING=$@

# Extract the deliver(email@address.se) part. Without the paranthesis.
TO=`echo $STRING|sed "s/.*deliver(//" | sed "s/).*//"`

# And check that the address is one that the email reflector handles.
COUNT=${#ACCEPTED[@]}
for (( i=0; i<$COUNT; i++ )); do
    ACCEPT=${ACCEPTED[$i]}
    if [ `echo $TO | grep $ACCEPT | wc -l` -gt 0 ]; then
	# It does!
	echo $TO
	exit
    fi
done

# No match. Return nothing.
