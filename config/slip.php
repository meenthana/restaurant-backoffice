<?php
// ตั้งค่าการตรวจสลิป (โหมดสาธิต หรือใส่ API key)

// Endpoint ของ SlipOK — {branch_id} จะถูกแทนค่าตอนเรียก
const SLIPOK_ENDPOINT   = 'https://api.slipok.com/api/line/apikey/{branch_id}';
const SLIPOK_BRANCH_ID  = '';   // เช่น '12345'
const SLIPOK_API_KEY    = '';   // เช่น 'SLIPOKxxxxxxxx'

// ชื่อบัญชีผู้รับที่ถูกต้องของร้าน (ใช้ตรวจว่าโอนเข้าบัญชีร้านจริง)
const SHOP_RECEIVER_NAME = '';  // เช่น 'นาย ธนา ศ'

// เปิด/ปิดการตรวจสลิปทั้งระบบ
const SLIP_VERIFY_ENABLED = true;

// โฟลเดอร์เก็บไฟล์สลิป (สัมพัทธ์กับ root ของโปรเจกต์)
const SLIP_UPLOAD_DIR = 'uploads/slips';
