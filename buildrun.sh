docker build -t dominos_voucher_puppet . &&\
docker run --name events --rm -it --sysctl net.ipv6.conf.all.disable_ipv6=1 --expose 9229 \
    --shm-size 1G -p 9229:9229 dominos_voucher_puppet
