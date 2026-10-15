// RFC 4733 (RTP telephone-event) when the call negotiated it, else SIP INFO (JsSIP's default).
// Cloudonix does not document which one it expects; RTP events are the WebRTC standard path.
export function dtmfTransportFor(session: any): 'RFC2833' | 'INFO' {
  const sender = session?.connection
    ?.getSenders()
    .find((s: RTCRtpSender) => s.track?.kind === 'audio');
  return sender?.dtmf?.canInsertDTMF ? 'RFC2833' : 'INFO';
}
