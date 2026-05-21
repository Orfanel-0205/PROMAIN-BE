// components/telemedicine/WebRTCSession.tsx
'use client';

import React, { useEffect, useRef, useState, useCallback } from 'react';
import { Button, Badge, Tooltip, message, Spin } from 'antd';
import {
    AudioOutlined, AudioMutedOutlined,
    VideoCameraOutlined, VideoCameraAddOutlined,
    DesktopOutlined, PhoneOutlined,
    ExpandOutlined, MessageOutlined,
} from '@ant-design/icons';
import api from '@/lib/api';

interface WebRTCSessionProps {
    sessionId: number;
    onSessionEnd: () => void;
    sidePanel?: React.ReactNode;
}

interface JoinData {
    room_id:      string;
    user_id:      number;
    user_name:    string;
    is_initiator: boolean;
    ice_servers:  RTCIceServer[];
    session_id:   number;
}

export default function WebRTCSession({
    sessionId,
    onSessionEnd,
    sidePanel,
}: WebRTCSessionProps) {
    // Refs
    const localVideoRef  = useRef<HTMLVideoElement>(null);
    const remoteVideoRef = useRef<HTMLVideoElement>(null);
    const pcRef          = useRef<RTCPeerConnection | null>(null);
    const localStreamRef = useRef<MediaStream | null>(null);
    const pollInterval   = useRef<NodeJS.Timeout | null>(null);

    // State
    const [joinData,         setJoinData]        = useState<JoinData | null>(null);
    const [connectionState,  setConnectionState]  = useState<string>('initializing');
    const [isAudioMuted,     setIsAudioMuted]     = useState(false);
    const [isVideoMuted,     setIsVideoMuted]     = useState(false);
    const [isScreenSharing,  setIsScreenSharing]  = useState(false);
    const [sessionDuration,  setSessionDuration]  = useState(0);
    const [remoteUserId,     setRemoteUserId]     = useState<number | null>(null);
    const [showSidePanel,    setShowSidePanel]    = useState(true);
    const [loading,          setLoading]          = useState(true);

    // ── Timer ─────────────────────────────────────────────────────
    useEffect(() => {
        if (connectionState === 'connected') {
            const timer = setInterval(() => setSessionDuration((s) => s + 1), 1000);
            return () => clearInterval(timer);
        }
    }, [connectionState]);

    // ── Initialize ────────────────────────────────────────────────
    useEffect(() => {
        const initSession = async () => {
            try {
                const res = await api.get(`/telemedicine/sessions/${sessionId}/join`);
                setJoinData(res.data.data);
                setLoading(false);
            } catch (err) {
                message.error('Failed to get session credentials.');
                setLoading(false);
            }
        };
        initSession();
        return () => cleanup();
    }, [sessionId]);

    const cleanup = () => {
        if (pollInterval.current) clearInterval(pollInterval.current);
        pcRef.current?.close();
        localStreamRef.current?.getTracks().forEach((t) => t.stop());
        pcRef.current = null;
        localStreamRef.current = null;
    };

    // ── Start WebRTC after joinData is available ──────────────────
    useEffect(() => {
        if (joinData) startWebRTC();
    }, [joinData]);

    const startWebRTC = async () => {
        if (!joinData) return;

        try {
            setConnectionState('getting_media');

            // Get local media
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { width: 1280, height: 720, facingMode: 'user' },
                audio: { echoCancellation: true, noiseSuppression: true },
            });

            localStreamRef.current = stream;
            if (localVideoRef.current) {
                localVideoRef.current.srcObject = stream;
            }

            // Create peer connection
            const pc = new RTCPeerConnection({
                iceServers: joinData.ice_servers,
                iceTransportPolicy: 'all',
            });
            pcRef.current = pc;

            // Add local tracks to peer connection
            stream.getTracks().forEach((track) => pc.addTrack(track, stream));

            // Handle remote stream
            pc.ontrack = (event) => {
                if (remoteVideoRef.current && event.streams[0]) {
                    remoteVideoRef.current.srcObject = event.streams[0];
                }
            };

            // Handle ICE candidates
            pc.onicecandidate = async (event) => {
                if (event.candidate && remoteUserId) {
                    await sendSignal('ice_candidate', {
                        candidate: event.candidate.toJSON(),
                    });
                }
            };

            pc.onconnectionstatechange = () => {
                setConnectionState(pc.connectionState);
                if (pc.connectionState === 'disconnected') {
                    message.warning('Connection interrupted. Trying to reconnect...');
                }
            };

            pc.oniceconnectionstatechange = () => {
                if (pc.iceConnectionState === 'connected') {
                    setConnectionState('connected');
                }
            };

            // Signal to partner that we're ready
            await api.post(`/telemedicine/sessions/${sessionId}/signal`, {
                receiver_id: -1, // Broadcast ready signal (handle in backend or just use a known peer id)
                type: 'ready',
                payload: { user_id: joinData.user_id, user_name: joinData.user_name }
            });

            setConnectionState('waiting_for_peer');

            // Start polling for signals
            pollInterval.current = setInterval(pollSignals, 1500);

            // Doctor creates the offer
            if (joinData.is_initiator) {
                // Wait for peer to be ready before sending offer
            }

        } catch (err: any) {
            setConnectionState('error');
            if (err.name === 'NotAllowedError') {
                message.error('Camera/microphone access denied. Please allow access and refresh.');
            } else {
                message.error('Failed to start video. ' + err.message);
            }
        }
    };

    const pollSignals = async () => {
        try {
            const res = await api.get(`/telemedicine/sessions/${sessionId}/signals`);
            const signals = res.data.data;

            for (const signal of signals) {
                await handleSignal(signal);
            }
        } catch {}
    };

    const handleSignal = async (signal: any) => {
        const pc = pcRef.current;
        if (!pc || !joinData) return;

        if (signal.sender_id !== joinData.user_id) {
            setRemoteUserId(signal.sender_id);
        }

        switch (signal.type) {
            case 'ready':
                if (joinData.is_initiator && pc.signalingState === 'stable') {
                    const offer = await pc.createOffer();
                    await pc.setLocalDescription(offer);
                    await api.post(`/telemedicine/sessions/${sessionId}/signal`, {
                        receiver_id: signal.sender_id,
                        type: 'offer',
                        payload: { sdp: offer },
                    });
                }
                break;

            case 'offer':
                await pc.setRemoteDescription(new RTCSessionDescription(signal.payload.sdp));
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await api.post(`/telemedicine/sessions/${sessionId}/signal`, {
                    receiver_id: signal.sender_id,
                    type: 'answer',
                    payload: { sdp: answer },
                });
                break;

            case 'answer':
                await pc.setRemoteDescription(new RTCSessionDescription(signal.payload.sdp));
                break;

            case 'ice_candidate':
                if (signal.payload.candidate) {
                    await pc.addIceCandidate(new RTCIceCandidate(signal.payload.candidate));
                }
                break;

            case 'hang_up':
                handleEndCall(false);
                break;
        }
    };

    const sendSignal = async (type: string, payload: any) => {
        if (remoteUserId) {
            await api.post(`/telemedicine/sessions/${sessionId}/signal`, {
                receiver_id: remoteUserId,
                type,
                payload,
            });
        }
    };

    const handleEndCall = async (sendSignalToRemote = true) => {
        if (sendSignalToRemote && remoteUserId) {
            await sendSignal('hang_up', {});
        }
        cleanup();
        onSessionEnd();
    };

    const toggleAudio = () => {
        const stream = localStreamRef.current;
        if (!stream) return;
        stream.getAudioTracks().forEach((t) => (t.enabled = isAudioMuted));
        setIsAudioMuted(!isAudioMuted);
    };

    const toggleVideo = () => {
        const stream = localStreamRef.current;
        if (!stream) return;
        stream.getVideoTracks().forEach((t) => (t.enabled = isVideoMuted));
        setIsVideoMuted(!isVideoMuted);
    };

    const formatDuration = (seconds: number) => {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    };

    if (loading) return <div style={{ textAlign: 'center', padding: 50 }}><Spin tip="Loading session..." /></div>;

    return (
        <div style={{
            display: 'flex',
            height: 'calc(100vh - 120px)',
            gap: 16,
            background: '#0f172a',
            borderRadius: 16,
            overflow: 'hidden',
            padding: 8,
        }}>
            <div style={{ flex: 1, position: 'relative', borderRadius: 12, overflow: 'hidden' }}>
                <video
                    ref={remoteVideoRef}
                    autoPlay
                    playsInline
                    style={{
                        width: '100%', height: '100%',
                        objectFit: 'cover',
                        background: '#1e293b',
                    }}
                />

                <div style={{
                    position: 'absolute', bottom: 80, right: 16,
                    width: 200, height: 150,
                    borderRadius: 12, overflow: 'hidden',
                    border: '2px solid rgba(255,255,255,0.2)',
                }}>
                    <video
                        ref={localVideoRef}
                        autoPlay
                        playsInline
                        muted
                        style={{
                            width: '100%', height: '100%',
                            objectFit: 'cover',
                            transform: 'scaleX(-1)',
                        }}
                    />
                </div>

                <div style={{
                    position: 'absolute', top: 16, left: 16,
                    display: 'flex', alignItems: 'center', gap: 12,
                }}>
                    <Badge
                        color={connectionState === 'connected' ? 'green' : 'orange'}
                        text={<span style={{ color: 'white' }}>{connectionState === 'connected' ? `LIVE - ${formatDuration(sessionDuration)}` : 'Connecting...'}</span>}
                    />
                </div>

                <div style={{
                    position: 'absolute', bottom: 16, left: '50%',
                    transform: 'translateX(-50%)',
                    display: 'flex', gap: 12, alignItems: 'center',
                    background: 'rgba(0,0,0,0.7)',
                    borderRadius: 50, padding: '10px 24px',
                }}>
                    <Button shape="circle" icon={isAudioMuted ? <AudioMutedOutlined /> : <AudioOutlined />} onClick={toggleAudio} />
                    <Button shape="circle" icon={isVideoMuted ? <VideoCameraAddOutlined /> : <VideoCameraOutlined />} onClick={toggleVideo} />
                    <Button shape="circle" danger icon={<PhoneOutlined rotate={135} />} onClick={() => handleEndCall(true)} />
                    <Button shape="circle" icon={<MessageOutlined />} onClick={() => setShowSidePanel(!showSidePanel)} />
                </div>
            </div>

            {showSidePanel && sidePanel && (
                <div style={{ width: 380, background: 'white', borderRadius: 12, padding: 20, overflow: 'auto' }}>
                    {sidePanel}
                </div>
            )}
        </div>
    );
}