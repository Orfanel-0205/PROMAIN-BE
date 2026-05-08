// components/chatbot/KaAgapayChat.tsx
'use client';

import React, { useState, useRef, useEffect } from 'react';
import { Button, Input, List, Avatar, Card, Badge, Spin } from 'antd';
import { SendOutlined, RobotOutlined, UserOutlined, MessageOutlined, CloseOutlined } from '@ant-design/icons';
import api from '@/lib/api';

interface Message {
    role: 'user' | 'assistant';
    content: string;
}

export default function KaAgapayChat() {
    const [isOpen, setIsOpen] = useState(false);
    const [messages, setMessages] = useState<Message[]>([
        { role: 'assistant', content: 'Hello! I am Ka-agapay-Al. How can I help you today?' }

    ]);
    const [inputValue, setInputValue] = useState('');
    const [loading, setLoading] = useState(false);
    const scrollRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (scrollRef.current) {
            const scrollContainer = scrollRef.current;
            setTimeout(() => {
                scrollContainer.scrollTop = scrollContainer.scrollHeight;
            }, 100);
        }
    }, [messages, loading]);


    const handleSend = async () => {
        if (!inputValue.trim() || loading) return;

        const userMessage = inputValue.trim();
        setInputValue('');
        setMessages(prev => [...prev, { role: 'user', content: userMessage }]);
        setLoading(true);

        try {
            const res = await api.post('/chat/message', {
                message: userMessage,
                history: messages.slice(-10) // Send last 10 messages for context
            });

            setMessages(prev => [...prev, { role: 'assistant', content: res.data.message.content }]);
        } catch (err) {
            setMessages(prev => [...prev, { role: 'assistant', content: "I'm sorry, I'm having trouble connecting. Please try again." }]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div style={{ position: 'fixed', bottom: 24, right: 24, zIndex: 1000 }}>
            {!isOpen ? (
                <Button
                    type="primary"
                    shape="circle"
                    icon={<MessageOutlined style={{ fontSize: 24 }} />}
                    size="large"
                    onClick={() => setIsOpen(true)}
                    style={{ width: 60, height: 60, boxShadow: '0 4px 12px rgba(29, 78, 216, 0.4)' }}
                />
            ) : (
                <Card
                    title={
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <RobotOutlined style={{ color: '#1d4ed8' }} />
                            <span>Ka-agapay-Al Assistant</span>
                        </div>

                    }
                    extra={<CloseOutlined onClick={() => setIsOpen(false)} style={{ cursor: 'pointer', color: '#64748b' }} />}
                    style={{
                        width: 380,
                        height: 520,
                        borderRadius: 16,
                        boxShadow: '0 8px 32px rgba(0,0,0,0.2)',
                        border: '1px solid #e2e8f0',
                    }}
                    styles={{
                        body: {
                            height: '460px',
                            display: 'flex',
                            flexDirection: 'column',
                            padding: 0,
                            overflow: 'hidden'
                        }
                    }}
                >
                    <div
                        ref={scrollRef}
                        style={{
                            flex: 1,
                            overflowY: 'auto',
                            padding: '16px 16px 8px 16px',
                            background: '#f8fafc',
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 12,
                        }}
                    >

                        {messages.map((msg, i) => (
                            <div
                                key={i}
                                style={{
                                    alignSelf: msg.role === 'user' ? 'flex-end' : 'flex-start',
                                    maxWidth: '80%',
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: 4
                                }}
                            >
                                <div
                                    style={{
                                        background: msg.role === 'user' ? '#1d4ed8' : '#ffffff',
                                        color: msg.role === 'user' ? 'white' : '#1e293b',
                                        padding: '10px 14px',
                                        borderRadius: msg.role === 'user' ? '18px 18px 0 18px' : '18px 18px 18px 0',
                                        boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                                        fontSize: 14,
                                        lineHeight: 1.5
                                    }}
                                >
                                    {msg.content}
                                </div>
                            </div>
                        ))}
                        {loading && (
                            <div style={{ alignSelf: 'flex-start', padding: '0 12px' }}>
                                <Spin size="small" />
                            </div>
                        )}
                    </div>

                    <div style={{ padding: 12, borderTop: '1px solid #e2e8f0', background: 'white' }}>
                        <Input
                            placeholder="Type your health question..."
                            value={inputValue}
                            onChange={(e) => setInputValue(e.target.value)}
                            onPressEnter={handleSend}
                            suffix={
                                <SendOutlined
                                    onClick={handleSend}
                                    style={{ color: '#1d4ed8', cursor: 'pointer' }}
                                />
                            }
                            disabled={loading}
                            style={{ borderRadius: 20 }}
                        />
                    </div>
                </Card>
            )}
        </div>
    );
}
