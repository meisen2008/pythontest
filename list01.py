#!/usr/bin/python
#coding=utf-8
#demo
list1 = ['eee',23,'dsdsd','sdsd4','qqq',23,23]
print list1[2]

#list1[2] = 33
del list1[3]
print list1

list1.append('ooo')
print list1

print list1.count(23)

list2 = [99,88,23]
list1.extend(list2)
print list1;
print list1.index(99)

list1.insert(2,100)
print list1

list1.pop()
print list1

list1.pop(3)
print list1

ff = 23
list1.remove(99)
print list1