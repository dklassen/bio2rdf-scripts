#ChEMBL

This is the parser for the ChEML database version 15 parser

NOTE: This parser is currently being updated to be fully compatible

## Mysql Installation and Configuration

Below are the instructions to get a mysql database up and running on the system that you plan to use.

###Mac

The easiest way is to install the HomeBrew package manager. Instructions can be
found 'here'. Once installed type the following in the 
command line:

> brew install mysql

##Database Installation

1. Log into MySQL database server where you intend to load chembl data and
   run the following command to create new database:

    mysql> create database chembl_15;

2. Logout of database and run the following command to laod data. You will
   need to replace USERNAME, PASSWORD, HOST and PORT with local settings. 
   Depending on your database setup you may not need the host and port
   arguments. 
   
    $> mysql -uUSERNAME -pPASSWORD [-hHOST -PPORT] chembl_14 < /path/to/chembl_14.mysqldump.sql

##Running the Script

The script is run slightly differently than normal bio2rdf scripts as you need
to point the script to the to the mysql database

> php chembl.php user="enterusername" pass="enterpass" db_name="where you
> putchemlb" outdir="where you want the output" files="which section of the
> datbase you want"

Note you do not put the quotes in the string above
