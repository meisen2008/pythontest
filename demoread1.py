#!/usr/bin/python
# -*- coding: UTF-8 -*-
 
# 打开一个文件
fo = open("demo1.py", "r+")
str = fo.read(10);
print "读取的字符串是 : ", str
# 关闭打开的文件
fo.close()