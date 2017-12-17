echo "Running site with DB $db_name via user $db_user with bot at $bot_url"
docker stop lamp > /dev/null 2>&1
docker rm lamp > /dev/null 2>&1
docker run -e db_name -e db_user -e db_pass -e bot_url -v `pwd`/site:/srv/http --name lamp \
	--net dominos --ip 172.18.0.3 -p 443:443 -d greyltc/lamp
echo "Waiting for container to start"
while ! docker exec -it lamp mysql mysql -e "show databases" >/dev/null 2>&1; do
	sleep 0.1
done	
echo "Updating settings in container"
docker exec -it lamp sed -i "s/AllowOverride None/AllowOverride All/g" /etc/httpd/conf/httpd.conf
docker exec -it lamp apachectl restart
docker exec -it lamp mysql mysql -e "create user '$db_user'@'localhost' identified by '$db_pass'"
docker exec -it lamp mysql mysql -e "grant all on $db_name.* to '$db_user'@'localhost'"
docker exec -it lamp mysql mysql -e "create database $db_name"
docker exec -it lamp mysql $db_name -e "$(cat createdb.sql)"